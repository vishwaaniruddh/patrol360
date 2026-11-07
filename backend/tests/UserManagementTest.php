<?php
// backend/tests/UserManagementTest.php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Controllers\UserController;
use App\Database;
use PDO;

final class UserManagementTest extends TestCase
{
    private PDO $db;
    private UserController $controller;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
        $this->controller = new UserController();
    }

    public function testUserControllerMethodsExist(): void
    {
        $this->assertTrue(method_exists($this->controller, 'index'));
        $this->assertTrue(method_exists($this->controller, 'show'));
        $this->assertTrue(method_exists($this->controller, 'store'));
        $this->assertTrue(method_exists($this->controller, 'update'));
        $this->assertTrue(method_exists($this->controller, 'destroy'));
        $this->assertTrue(method_exists($this->controller, 'delete'));
        $this->assertTrue(method_exists($this->controller, 'uploadFaceEncoding'));
        $this->assertTrue(method_exists($this->controller, 'photo'));
    }

    public function testUserCreationAndDeletionLifecycle(): void
    {
        // 1. Create a temporary test officer
        $testEmail = 'test_officer_' . time() . '@police.gov.in';
        $stmt = $this->db->prepare("INSERT INTO `users` (`name`, `email`, `password`, `role`, `badge_number`, `police_station`, `is_active`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['Test Officer Lifecycle', $testEmail, password_hash('Pass123!', PASSWORD_BCRYPT), 'user', 'TEST-BADGE', 'Test Station', 1]);
        $testId = (int)$this->db->lastInsertId();

        $this->assertGreaterThan(0, $testId);

        // Verify user exists
        $check = $this->db->prepare("SELECT id FROM `users` WHERE id = ?");
        $check->execute([$testId]);
        $this->assertNotEmpty($check->fetch());

        // 2. Call destroy()
        ob_start();
        $this->controller->destroy([
            'params' => [(string)$testId],
            'body'   => [],
            'query'  => [],
            'user'   => ['id' => 999999, 'role' => 'admin'] // not self
        ]);
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertTrue($json['success'] ?? false, 'User destruction must succeed');

        // Verify user is removed from database
        $checkAfter = $this->db->prepare("SELECT id FROM `users` WHERE id = ?");
        $checkAfter->execute([$testId]);
        $this->assertFalse($checkAfter->fetch());
    }
}
