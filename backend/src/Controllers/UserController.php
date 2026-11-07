<?php
// backend/src/Controllers/UserController.php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Helpers\Validator;
use PDO;

class UserController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    private function getOfficerPhotoUrl(int $userId): ?string
    {
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/officers';
        if (!is_dir($uploadDir)) {
            return null;
        }
        $files = glob($uploadDir . '/officer_' . $userId . '_*.*');
        if (!empty($files)) {
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            return '/uploads/officers/' . basename($files[0]);
        }
        return null;
    }

    public function index(array $args): void
    {
        $query = $args['query'];
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, min(100, (int)($query['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $role = $query['role'] ?? null;
        $search = $query['search'] ?? null;

        $where = [];
        $params = [];

        if ($role && in_array($role, ['admin', 'user'], true)) {
            $where[] = "`role` = ?";
            $params[] = $role;
        }

        if ($search) {
            $where[] = "(`name` LIKE ? OR `email` LIKE ? OR `badge_number` LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `users` {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Data query
        $sql = "SELECT id, name, email, role, badge_number, police_station, is_active, 
                       (CASE WHEN `faceEncoding` IS NOT NULL AND LENGTH(`faceEncoding`) > 0 THEN 1 ELSE 0 END) AS has_face_encoding,
                       created_at, updated_at 
                FROM `users` {$whereClause} 
                ORDER BY id DESC 
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $u['has_face_encoding'] = (int)($u['has_face_encoding'] ?? 0);
            $u['photo_url'] = $this->getOfficerPhotoUrl((int)$u['id']);
        }
        unset($u);

        Response::paginate($users, $total, $page, $limit, 'Users list retrieved');
    }

    public function show(array $args): void
    {
        $id = (int)$args['params'][0];
        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, 
                                           (CASE WHEN `faceEncoding` IS NOT NULL AND LENGTH(`faceEncoding`) > 0 THEN 1 ELSE 0 END) AS has_face_encoding,
                                           created_at, updated_at 
                                    FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        $user['has_face_encoding'] = (int)($user['has_face_encoding'] ?? 0);
        $user['photo_url'] = $this->getOfficerPhotoUrl($id);

        Response::success('User details retrieved', $user);
    }

    public function store(array $args): void
    {
        $body = $args['body'];

        $validator = Validator::make($body)
            ->required(['name', 'email', 'password', 'role'])
            ->email('email')
            ->in('role', ['admin', 'user'])
            ->minLength('password', 6);

        if ($validator->fails()) {
            Response::error('Validation failed', 422, $validator->errors());
        }

        $stmt = $this->db->prepare("SELECT id FROM `users` WHERE `email` = ? LIMIT 1");
        $stmt->execute([$body['email']]);
        if ($stmt->fetch()) {
            Response::error('Email is already registered.', 409);
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT);
        $badge = $body['badge_number'] ?? null;
        $station = $body['police_station'] ?? null;
        $active = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

        $insert = $this->db->prepare("
            INSERT INTO `users` (`name`, `email`, `password`, `role`, `badge_number`, `police_station`, `is_active`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$body['name'], $body['email'], $hash, $body['role'], $badge, $station, $active]);

        $newId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, created_at FROM `users` WHERE id = ?");
        $stmt->execute([$newId]);
        $newUser = $stmt->fetch();

        Response::success('User created successfully', $newUser, 201);
    }

    public function update(array $args): void
    {
        $id = (int)$args['params'][0];
        $body = $args['body'];

        $stmt = $this->db->prepare("SELECT * FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        $fields = [];
        $values = [];

        if (isset($body['name'])) {
            $fields[] = "`name` = ?";
            $values[] = $body['name'];
        }

        if (isset($body['email'])) {
            if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email address.', 422);
            }
            // Check unique email
            $check = $this->db->prepare("SELECT id FROM `users` WHERE `email` = ? AND id != ?");
            $check->execute([$body['email'], $id]);
            if ($check->fetch()) {
                Response::error('Email already in use by another user.', 409);
            }
            $fields[] = "`email` = ?";
            $values[] = $body['email'];
        }

        if (isset($body['role']) && in_array($body['role'], ['admin', 'user'], true)) {
            $fields[] = "`role` = ?";
            $values[] = $body['role'];
        }

        if (isset($body['badge_number'])) {
            $fields[] = "`badge_number` = ?";
            $values[] = $body['badge_number'];
        }

        if (isset($body['police_station'])) {
            $fields[] = "`police_station` = ?";
            $values[] = $body['police_station'];
        }

        if (isset($body['is_active'])) {
            $fields[] = "`is_active` = ?";
            $values[] = (int)(bool)$body['is_active'];
        }

        if (!empty($body['password'])) {
            if (mb_strlen($body['password']) < 6) {
                Response::error('Password must be at least 6 characters.', 422);
            }
            $fields[] = "`password` = ?";
            $values[] = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) {
            Response::error('No fields provided to update.', 400);
        }

        $values[] = $id;
        $sql = "UPDATE `users` SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        $stmt = $this->db->prepare("SELECT id, name, email, role, badge_number, police_station, is_active, updated_at FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $updated = $stmt->fetch();

        Response::success('User updated successfully', $updated);
    }

    public function destroy(array $args): void
    {
        $this->delete($args);
    }

    /**
     * POST /api/users/:id/face-encoding
     * POST /api/users/:id/photo
     * Uploads officer face photo and calls Python service (195.35.7.83:5051) to generate face encoding in DB
     */
    public function uploadFaceEncoding(array $args): void
    {
        $id = (int)($args['params'][0] ?? 0);
        $stmt = $this->db->prepare("SELECT id, name, badge_number FROM `users` WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found.', 404);
        }

        $tmpFilePath = null;
        $originalExt = 'jpg';
        $isTempGenerated = false;

        if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $tmpFilePath = $_FILES['image']['tmp_name'];
            $origName = $_FILES['image']['name'] ?? 'face.jpg';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $originalExt = $ext;
            }
        } elseif (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $tmpFilePath = $_FILES['photo']['tmp_name'];
            $origName = $_FILES['photo']['name'] ?? 'face.jpg';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $originalExt = $ext;
            }
        } elseif (!empty($args['body']['image']) || !empty($args['body']['photo'])) {
            $base64Data = (string)($args['body']['image'] ?? $args['body']['photo']);
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $typeMatch)) {
                $originalExt = strtolower($typeMatch[1]);
                $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            }
            $decoded = base64_decode($base64Data);
            if ($decoded !== false) {
                $tmpFilePath = tempnam(sys_get_temp_dir(), 'officer_face_');
                file_put_contents($tmpFilePath, $decoded);
                $isTempGenerated = true;
            }
        }

        if (!$tmpFilePath || !file_exists($tmpFilePath)) {
            Response::error('No face image provided. Please select or capture an image file.', 400);
        }

        // Save persistent copy in public/uploads/officers/
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/officers';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $savedFilename = 'officer_' . $id . '_' . time() . '.' . ($originalExt === 'jpeg' ? 'jpg' : $originalExt);
        $savedPath = $uploadDir . '/' . $savedFilename;
        copy($tmpFilePath, $savedPath);

        if ($isTempGenerated) {
            @unlink($tmpFilePath);
        }

        $publicPhotoUrl = '/uploads/officers/' . $savedFilename;

        // Forward to Python AI microservice: http://195.35.7.83:5051/generatefaceencoding_file
        $pythonApiUrl = 'http://195.35.7.83:5051/generatefaceencoding_file';
        $ch = curl_init();
        $mime = ($originalExt === 'png') ? 'image/png' : 'image/jpeg';
        $cfile = new \CURLFile($savedPath, $mime, basename($savedPath));

        curl_setopt_array($ch, [
            CURLOPT_URL            => $pythonApiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'emp_id' => (string)$id,
                'image'  => $cfile
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $pythonResponse = !empty($responseRaw) ? json_decode($responseRaw, true) : null;

        // Python service returns {"Code": 200, "msg": "Face embedding generated successfully", "emp_id": "..."}
        if ($httpCode === 200 && is_array($pythonResponse) && ($pythonResponse['Code'] ?? 0) === 200) {
            Response::success('Face embedding generated successfully', [
                'user_id'         => $id,
                'emp_id'          => (string)$id,
                'officer_name'    => $user['name'],
                'photo_url'       => $publicPhotoUrl,
                'has_face_encoding' => 1,
                'python_response' => $pythonResponse
            ], 200);
        } elseif ($httpCode === 422 || ($pythonResponse['Code'] ?? 0) === 422) {
            Response::error($pythonResponse['msg'] ?? 'No face detected in the uploaded photo. Please upload a clear frontal face image.', 422, [
                'user_id'         => $id,
                'photo_url'       => $publicPhotoUrl,
                'python_response' => $pythonResponse
            ]);
        } else {
            $msg = $pythonResponse['msg'] ?? ($curlError ?: 'Python face encoding microservice connection error');
            Response::error('Face encoding generation failed: ' . $msg, ($httpCode >= 400 && $httpCode < 600) ? $httpCode : 502, [
                'user_id'         => $id,
                'photo_url'       => $publicPhotoUrl,
                'python_response' => $pythonResponse,
                'curl_error'      => $curlError
            ]);
        }
    }

    /**
     * GET /api/users/:id/photo
     * Serves officer photo binary
     */
    public function photo(array $args): void
    {
        $id = (int)($args['params'][0] ?? 0);
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/officers';
        if (!is_dir($uploadDir)) {
            Response::error('Photo not found', 404);
        }

        $files = glob($uploadDir . '/officer_' . $id . '_*.*');
        if (empty($files)) {
            Response::error('No photo registered for this officer', 404);
        }

        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $files[0];
        $ext = strtolower(pathinfo($latest, PATHINFO_EXTENSION));
        $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($latest));
        header('Cache-Control: public, max-age=86400');
        readfile($latest);
        exit;
    }
}
