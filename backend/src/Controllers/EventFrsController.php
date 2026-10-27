<?php
// backend/src/Controllers/EventFrsController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class EventFrsController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * GET /api/events/frs
     * List facial recognition events
     */
    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $status = $query['status'] ?? null;
        $search = $query['search'] ?? null;
        $vehicleId = $query['vehicle_id'] ?? null;

        $where = [];
        $params = [];

        if ($status && in_array($status, ['PENDING', 'CONFIRMED', 'DISMISSED'], true)) {
            $where[] = "f.`operator_review_status` = ?";
            $params[] = $status;
        }

        if ($vehicleId) {
            $where[] = "f.`vehicle_id` = ?";
            $params[] = $vehicleId;
        }

        if ($search) {
            $where[] = "(f.`person_name` LIKE ? OR f.`matched_person_id` LIKE ? OR f.`event_id` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `frs_detection_events` f {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT f.*, 
                       w.crime_category, 
                       w.fir_number as watchlist_fir, 
                       w.photo_url as watchlist_photo,
                       w.alert_level as watchlist_alert_level
                FROM `frs_detection_events` f
                LEFT JOIN `person_watchlist` w ON f.matched_person_id = w.person_id
                {$whereClause}
                ORDER BY f.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll();

        Response::paginated($events, $total, $page, $limit, 'FRS detection events retrieved');
    }

    /**
     * GET /api/events/frs/:id
     * Single FRS event
     */
    public function show(array $args): void
    {
        $id = $args['params'][0] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $sql = "SELECT f.*, 
                       w.crime_category, 
                       w.fir_number as watchlist_fir, 
                       w.photo_url as watchlist_photo,
                       w.alert_level as watchlist_alert_level
                FROM `frs_detection_events` f
                LEFT JOIN `person_watchlist` w ON f.matched_person_id = w.person_id
                WHERE f.id = ? OR f.event_id = ?
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $id]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('FRS event not found', 404);
        }

        Response::success($event, 'FRS event details');
    }

    /**
     * POST /api/events/frs
     * Ingest face recognition match
     */
    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['vehicle_id', 'camera_id', 'match_confidence'])
            ->numeric('match_confidence');

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $eventId = $body['event_id'] ?? ('EVT-FRS-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
        $matchedPersonId = $body['matched_person_id'] ?? null;
        $personName = $body['person_name'] ?? null;

        // Auto lookup watchlist if person_id provided but name not provided
        if ($matchedPersonId && empty($personName)) {
            $lookupStmt = $this->db->prepare("SELECT full_name FROM `person_watchlist` WHERE person_id = ? LIMIT 1");
            $lookupStmt->execute([$matchedPersonId]);
            $personName = $lookupStmt->fetchColumn() ?: 'Suspect ' . $matchedPersonId;
        }

        $sql = "INSERT INTO `frs_detection_events` 
                (`event_id`, `vehicle_id`, `camera_id`, `matched_person_id`, `person_name`, 
                 `match_confidence`, `liveness_score`, `latitude`, `longitude`, 
                 `face_crop_url`, `video_clip_url`, `operator_review_status`, `hq_sync_status`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $eventId,
            $body['vehicle_id'],
            $body['camera_id'],
            $matchedPersonId,
            $personName,
            $body['match_confidence'],
            $body['liveness_score'] ?? null,
            $body['latitude'] ?? null,
            $body['longitude'] ?? null,
            $body['face_crop_url'] ?? null,
            $body['video_clip_url'] ?? null,
            $body['hq_sync_status'] ?? 'DELIVERED'
        ]);

        $insertedId = $this->db->lastInsertId();

        Response::success([
            'id'                     => (int)$insertedId,
            'event_id'               => $eventId,
            'matched_person_id'      => $matchedPersonId,
            'person_name'            => $personName,
            'operator_review_status' => 'PENDING'
        ], 'FRS detection event recorded', 201);
    }

    /**
     * PUT /api/events/frs/:id/verify
     * Human-In-The-Loop review (Confirm or Dismiss face match)
     */
    public function verify(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];
        $user = $args['user'] ?? null;

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $validator = Validator::make($body)
            ->required(['review_status'])
            ->in('review_status', ['CONFIRMED', 'DISMISSED', 'PENDING']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $checkStmt = $this->db->prepare("SELECT id FROM `frs_detection_events` WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            Response::error('FRS event not found', 404);
        }

        $reviewer = $user['email'] ?? $body['reviewed_by'] ?? 'In-Vehicle Officer';

        $sql = "UPDATE `frs_detection_events` 
                SET `operator_review_status` = ?, 
                    `reviewed_by` = ?, 
                    `reviewed_at` = CURRENT_TIMESTAMP 
                WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$body['review_status'], $reviewer, $id]);

        Response::success([
            'id'                     => (int)$id,
            'operator_review_status' => $body['review_status'],
            'reviewed_by'            => $reviewer,
            'reviewed_at'            => date('Y-m-d H:i:s')
        ], "FRS event review marked as {$body['review_status']}");
    }

    /**
     * PUT /api/events/frs/:id
     * Update FRS event
     */
    public function update(array $args): void
    {
        $id = $args['params'][0] ?? null;
        $body = $args['body'];

        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $fields = [];
        $params = [];

        $allowed = ['person_name', 'match_confidence', 'face_crop_url', 'video_clip_url', 'hq_sync_status'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "`{$f}` = ?";
                $params[] = $body[$f];
            }
        }

        if (empty($fields)) {
            Response::error('No update fields provided', 400);
        }

        $params[] = $id;
        $sql = "UPDATE `frs_detection_events` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        Response::success(['id' => (int)$id], 'FRS event updated successfully');
    }

    /**
     * DELETE /api/events/frs/:id
     * Admin only: Delete FRS event
     */
    public function destroy(array $args): void
    {
        $id = $args['params'][0] ?? null;
        if (!$id) {
            Response::error('Event ID is required', 400);
        }

        $stmt = $this->db->prepare("DELETE FROM `frs_detection_events` WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Event not found or already deleted', 404);
        }

        Response::success(null, 'FRS event deleted successfully');
    }

    /**
     * GET /api/events/frs/stats
     * FRS review stats
     */
    public function stats(array $args): void
    {
        $total = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events`")->fetchColumn();
        $pending = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events` WHERE operator_review_status = 'PENDING'")->fetchColumn();
        $confirmed = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events` WHERE operator_review_status = 'CONFIRMED'")->fetchColumn();
        $dismissed = (int)$this->db->query("SELECT COUNT(*) FROM `frs_detection_events` WHERE operator_review_status = 'DISMISSED'")->fetchColumn();

        Response::success([
            'total_detections'   => $total,
            'pending_review'     => $pending,
            'confirmed_hits'     => $confirmed,
            'dismissed'          => $dismissed
        ], 'FRS statistics');
    }
}
