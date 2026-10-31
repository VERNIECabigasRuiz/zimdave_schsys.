<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031013605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE grade_change_line (id INT AUTO_INCREMENT NOT NULL, from_value SMALLINT DEFAULT NULL, to_value SMALLINT NOT NULL, request_id INT NOT NULL, enrollment_id INT NOT NULL, UNIQUE INDEX uniq_grade_change_line (request_id, enrollment_id), INDEX IDX_289D322A427EB8A5 (request_id), INDEX IDX_289D322A8F7DB25B (enrollment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grade_change_request (id INT AUTO_INCREMENT NOT NULL, reason LONGTEXT NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, section_id INT NOT NULL, subject_id INT NOT NULL, grading_period_id INT NOT NULL, requested_by_id INT NOT NULL, decided_by_id INT DEFAULT NULL, INDEX idx_grade_change_class (section_id, subject_id, grading_period_id, status), INDEX IDX_46B9477BD823E37A (section_id), INDEX IDX_46B9477B23EDC87 (subject_id), INDEX IDX_46B9477BDF9D8D15 (grading_period_id), INDEX IDX_46B9477B4DA1E751 (requested_by_id), INDEX IDX_46B9477BE26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE grade_change_line ADD CONSTRAINT FK_289D322A427EB8A5 FOREIGN KEY (request_id) REFERENCES grade_change_request (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_change_line ADD CONSTRAINT FK_289D322A8F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_change_request ADD CONSTRAINT FK_46B9477BD823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_change_request ADD CONSTRAINT FK_46B9477B23EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grade_change_request ADD CONSTRAINT FK_46B9477BDF9D8D15 FOREIGN KEY (grading_period_id) REFERENCES grading_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_change_request ADD CONSTRAINT FK_46B9477B4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grade_change_request ADD CONSTRAINT FK_46B9477BE26B496B FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE grade_change_line DROP FOREIGN KEY FK_289D322A427EB8A5');
        $this->addSql('ALTER TABLE grade_change_line DROP FOREIGN KEY FK_289D322A8F7DB25B');
        $this->addSql('ALTER TABLE grade_change_request DROP FOREIGN KEY FK_46B9477BD823E37A');
        $this->addSql('ALTER TABLE grade_change_request DROP FOREIGN KEY FK_46B9477B23EDC87');
        $this->addSql('ALTER TABLE grade_change_request DROP FOREIGN KEY FK_46B9477BDF9D8D15');
        $this->addSql('ALTER TABLE grade_change_request DROP FOREIGN KEY FK_46B9477B4DA1E751');
        $this->addSql('ALTER TABLE grade_change_request DROP FOREIGN KEY FK_46B9477BE26B496B');
        $this->addSql('DROP TABLE grade_change_line');
        $this->addSql('DROP TABLE grade_change_request');
    }
}
