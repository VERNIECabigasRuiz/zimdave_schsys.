<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the default administrator account (zim / zimzone) on a fresh install.
 */
final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default administrator account: zim';
    }

    public function up(Schema $schema): void
    {
        $exists = $this->connection->fetchOne('SELECT COUNT(*) FROM `user` WHERE username = ?', ['zim']);
        if ((int) $exists > 0) {
            return;
        }

        // Symfony's "auto" password hasher verifies native bcrypt hashes
        // and upgrades them automatically on the next sign-in if needed.
        $this->addSql(
            'INSERT INTO `user` (username, full_name, roles, password, must_change_password, active, created_at)
             VALUES (:username, :fullName, :roles, :password, 0, 1, NOW())',
            [
                'username' => 'zim',
                'fullName' => 'Admin',
                'roles' => '["ROLE_ADMIN"]',
                'password' => password_hash('zimzone', \PASSWORD_BCRYPT),
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `user` WHERE username = :username', ['username' => 'zim']);
    }
}
