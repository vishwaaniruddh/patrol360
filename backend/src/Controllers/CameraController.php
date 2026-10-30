<?php
// backend/src/Controllers/CameraController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Helpers\CameraHelper;
use PDO;

class CameraController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function index(array $args): void
    {
        $query = $args['query'];
        $vehicleId = $query['vehicle_id'] ?? null;

        if ($vehicleId) {
            $stmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE vehicle_id = ? ORDER BY mount_position ASC");
            $stmt->execute([$vehicleId]);
        } else {
            $stmt = $this->db->query("SELECT * FROM `vehicle_cameras` ORDER BY vehicle_id ASC, mount_position ASC");
        }

        $cameras = $stmt->fetchAll();
        $cameras = CameraHelper::formatCameras($cameras);
        Response::success('Cameras retrieved successfully', $cameras);
    }

    public function show(array $args): void
    {
        $id = $args['params'][0];
        $stmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE id = ? OR camera_id = ?");
        $stmt->execute([$id, $id]);
        $camera = $stmt->fetch();

        if (!$camera) {
            Response::error('Camera not found.', 404);
        }

        $camera = CameraHelper::formatCamera($camera);
        Response::success('Camera details retrieved', $camera);
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['camera_id', 'vehicle_id', 'camera_name', 'mount_position'])
            ->in('mount_position', ['FRONT', 'REAR', 'LEFT', 'RIGHT']);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        // Check if vehicle exists
        $vehCheck = $this->db->prepare("SELECT id FROM `police_vehicles` WHERE vehicle_id = ?");
        $vehCheck->execute([$body['vehicle_id']]);
        if (!$vehCheck->fetch()) {
            Response::error("Police Vehicle '{$body['vehicle_id']}' does not exist.", 404);
        }

        // Check unique camera_id
        $camCheck = $this->db->prepare("SELECT id FROM `vehicle_cameras` WHERE camera_id = ?");
        $camCheck->execute([$body['camera_id']]);
        if ($camCheck->fetch()) {
            Response::error("Camera ID '{$body['camera_id']}' already exists.", 409);
        }

        $res = $body['resolution'] ?? '1920x1080';
        $fps = (int)($body['fps'] ?? 25);
        $streaming = isset($body['is_streaming']) ? (int)(bool)$body['is_streaming'] : 1;
        $rtspUrl = !empty($body['rtsp_url']) ? trim($body['rtsp_url']) : '';

        // Dynamic DVR & Live Stream Configuration
        $username = isset($body['username']) ? trim((string)$body['username']) : null;
        $password = isset($body['password']) ? trim((string)$body['password']) : null;
        $dvrname  = isset($body['dvrname'])  ? trim((string)$body['dvrname'])  : null;
        $ip       = isset($body['ip'])       ? trim((string)$body['ip'])       : null;
        $dvrPort  = isset($body['dvr_port'])  ? trim((string)$body['dvr_port'])  : null;
        $rtspPort = isset($body['rtsp_port']) ? trim((string)$body['rtsp_port']) : '554';
        $channel  = isset($body['channel'])   ? (int)$body['channel']           : 1;

        // Auto-generate rtsp_url if missing but IP & credentials exist
        if (empty($rtspUrl) && !empty($ip)) {
            $auth = (!empty($username) && !empty($password)) ? "{$username}:{$password}@" : '';
            $rtspUrl = "rtsp://{$auth}{$ip}:{$rtspPort}/Streaming/Channels/{$channel}01";
        }

        $insert = $this->db->prepare("
            INSERT INTO `vehicle_cameras` (
                `camera_id`, `vehicle_id`, `camera_name`, `mount_position`, 
                `rtsp_url`, `resolution`, `fps`, `is_streaming`,
                `username`, `password`, `dvrname`, `ip`, `dvr_port`, `rtsp_port`, `channel`
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $body['camera_id'], $body['vehicle_id'], $body['camera_name'], $body['mount_position'],
            $rtspUrl, $res, $fps, $streaming,
            $username, $password, $dvrname, $ip, $dvrPort, $rtspPort, $channel
        ]);

        $newId = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE id = ?");
        $stmt->execute([$newId]);
        $newCam = CameraHelper::formatCamera($stmt->fetch());

        Response::success('Camera added to vehicle successfully', $newCam, 201);
    }

    public function update(array $args): void
    {
        $id = $args['params'][0];
        $body = $args['body'];

        $stmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE id = ? OR camera_id = ?");
        $stmt->execute([$id, $id]);
        $camera = $stmt->fetch();

        if (!$camera) {
            Response::error('Camera not found.', 404);
        }

        $fields = [];
        $values = [];

        if (isset($body['camera_name'])) {
            $fields[] = "`camera_name` = ?";
            $values[] = $body['camera_name'];
        }

        if (isset($body['mount_position']) && in_array($body['mount_position'], ['FRONT', 'REAR', 'LEFT', 'RIGHT'], true)) {
            $fields[] = "`mount_position` = ?";
            $values[] = $body['mount_position'];
        }

        if (isset($body['rtsp_url'])) {
            $fields[] = "`rtsp_url` = ?";
            $values[] = $body['rtsp_url'];
        }

        if (isset($body['resolution'])) {
            $fields[] = "`resolution` = ?";
            $values[] = $body['resolution'];
        }

        if (isset($body['fps'])) {
            $fields[] = "`fps` = ?";
            $values[] = (int)$body['fps'];
        }

        if (isset($body['is_streaming'])) {
            $fields[] = "`is_streaming` = ?";
            $values[] = (int)(bool)$body['is_streaming'];
        }

        // New DVR / Live View Configuration Fields
        if (array_key_exists('username', $body)) {
            $fields[] = "`username` = ?";
            $values[] = trim((string)$body['username']);
        }
        if (array_key_exists('password', $body)) {
            $fields[] = "`password` = ?";
            $values[] = trim((string)$body['password']);
        }
        if (array_key_exists('dvrname', $body)) {
            $fields[] = "`dvrname` = ?";
            $values[] = trim((string)$body['dvrname']);
        }
        if (array_key_exists('ip', $body)) {
            $fields[] = "`ip` = ?";
            $values[] = trim((string)$body['ip']);
        }
        if (array_key_exists('dvr_port', $body)) {
            $fields[] = "`dvr_port` = ?";
            $values[] = trim((string)$body['dvr_port']);
        }
        if (array_key_exists('rtsp_port', $body)) {
            $fields[] = "`rtsp_port` = ?";
            $values[] = trim((string)$body['rtsp_port']);
        }
        if (array_key_exists('channel', $body)) {
            $fields[] = "`channel` = ?";
            $values[] = (int)$body['channel'];
        }

        if (empty($fields)) {
            Response::error('No fields provided for update.', 400);
        }

        $values[] = $camera['id'];
        $sql = "UPDATE `vehicle_cameras` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE id = ?");
        $stmt->execute([$camera['id']]);
        $updated = CameraHelper::formatCamera($stmt->fetch());

        Response::success('Camera updated successfully', $updated);
    }

    public function destroy(array $args): void
    {
        $this->delete($args);
    }

    public function delete(array $args): void
    {
        $id = $args['params'][0];
        $stmt = $this->db->prepare("SELECT id, camera_id FROM `vehicle_cameras` WHERE id = ? OR camera_id = ?");
        $stmt->execute([$id, $id]);
        $camera = $stmt->fetch();

        if (!$camera) {
            Response::error('Camera not found.', 404);
        }

        $del = $this->db->prepare("DELETE FROM `vehicle_cameras` WHERE id = ?");
        $del->execute([$camera['id']]);

        Response::success("Camera '{$camera['camera_id']}' removed successfully");
    }

    /**
     * Live MJPEG video stream proxy for camera feeds
     * Prevents browser Mixed Content blocking and SSL protocol errors (ERR_SSL_PROTOCOL_ERROR) on HTTPS production servers
     */
    public function stream(array $args): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); ignore_user_abort(false);
        }

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('implicit_flush', '1');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @ob_implicit_flush(true);

        $id = $args['params'][0] ?? null;
        $name = $_GET['name'] ?? null;
        $targetUrl = null;

        if ($name) {
            $targetUrl = 'http://223.182.122.63:7000/?name=' . $name;
        } elseif ($id) {
            $stmt = $this->db->prepare("SELECT * FROM `vehicle_cameras` WHERE id = ? OR camera_id = ? LIMIT 1");
            $stmt->execute([$id, $id]);
            $camera = $stmt->fetch();
            if ($camera) {
                $targetUrl = CameraHelper::buildLiveViewUrl($camera);
            }
        }

        if (!$targetUrl) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Camera stream not found or missing credentials.";
            exit;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: Patrol360-Proxy/1.0\r\n"
            ]
        ]);

        $fp = @fopen($targetUrl, 'rb', false, $ctx);
        if (!$fp) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Unable to reach live streaming gateway at 223.182.122.63:7000";
            exit;
        }

        header('Content-Type: multipart/x-mixed-replace; boundary=frame');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: *');
        header('X-Accel-Buffering: no');

        fpassthru($fp);
        fclose($fp);
        exit;
    }

    public function streamDirect(array $args): void
    {
        $this->stream($args);
    }
}
