<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016011424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plans (Basic, Individual) with seats and expiry on schools';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, teachers_per_seat INT NOT NULL, students_per_seat INT NOT NULL, includes_principal TINYINT NOT NULL, UNIQUE INDEX UNIQ_DD5A5B7D5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("INSERT INTO plan (name, teachers_per_seat, students_per_seat, includes_principal) VALUES ('Basic', 15, 1000, 1), ('Individual', 1, 65, 0)");

        // Existing schools start on Basic, so plan_id is filled before it becomes required
        $this->addSql('ALTER TABLE school ADD seats INT DEFAULT 1 NOT NULL, ADD plan_valid_until DATE DEFAULT NULL, ADD plan_id INT DEFAULT NULL');
        $this->addSql("UPDATE school SET plan_id = (SELECT id FROM plan WHERE name = 'Basic')");
        $this->addSql('ALTER TABLE school MODIFY plan_id INT NOT NULL');
        $this->addSql('ALTER TABLE school ADD CONSTRAINT FK_F99EDABBE899029B FOREIGN KEY (plan_id) REFERENCES plan (id)');
        $this->addSql('CREATE INDEX IDX_F99EDABBE899029B ON school (plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE school DROP FOREIGN KEY FK_F99EDABBE899029B');
        $this->addSql('DROP INDEX IDX_F99EDABBE899029B ON school');
        $this->addSql('ALTER TABLE school DROP seats, DROP plan_valid_until, DROP plan_id');
        $this->addSql('DROP TABLE plan');
    }
}
