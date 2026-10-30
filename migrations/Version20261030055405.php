<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030055405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE grade (id INT AUTO_INCREMENT NOT NULL, value SMALLINT NOT NULL, updated_at DATETIME NOT NULL, enrollment_id INT NOT NULL, subject_id INT NOT NULL, grading_period_id INT NOT NULL, updated_by_id INT NOT NULL, UNIQUE INDEX uniq_grade (enrollment_id, subject_id, grading_period_id), INDEX IDX_595AAE348F7DB25B (enrollment_id), INDEX IDX_595AAE3423EDC87 (subject_id), INDEX IDX_595AAE34DF9D8D15 (grading_period_id), INDEX IDX_595AAE34896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grade_lock (id INT AUTO_INCREMENT NOT NULL, locked_at DATETIME NOT NULL, school_id INT NOT NULL, grading_period_id INT NOT NULL, locked_by_id INT NOT NULL, UNIQUE INDEX uniq_grade_lock (school_id, grading_period_id), INDEX IDX_29EDD61EC32A47EE (school_id), INDEX IDX_29EDD61EDF9D8D15 (grading_period_id), INDEX IDX_29EDD61E7A88E00 (locked_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grade_unlock (id INT AUTO_INCREMENT NOT NULL, unlocked_at DATETIME NOT NULL, section_id INT NOT NULL, subject_id INT NOT NULL, grading_period_id INT NOT NULL, unlocked_by_id INT NOT NULL, UNIQUE INDEX uniq_grade_unlock (section_id, subject_id, grading_period_id), INDEX IDX_284B31F1D823E37A (section_id), INDEX IDX_284B31F123EDC87 (subject_id), INDEX IDX_284B31F1DF9D8D15 (grading_period_id), INDEX IDX_284B31F1371F3A6E (unlocked_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE348F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE3423EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE34DF9D8D15 FOREIGN KEY (grading_period_id) REFERENCES grading_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE34896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grade_lock ADD CONSTRAINT FK_29EDD61EC32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_lock ADD CONSTRAINT FK_29EDD61EDF9D8D15 FOREIGN KEY (grading_period_id) REFERENCES grading_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_lock ADD CONSTRAINT FK_29EDD61E7A88E00 FOREIGN KEY (locked_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grade_unlock ADD CONSTRAINT FK_284B31F1D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_unlock ADD CONSTRAINT FK_284B31F123EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_unlock ADD CONSTRAINT FK_284B31F1DF9D8D15 FOREIGN KEY (grading_period_id) REFERENCES grading_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_unlock ADD CONSTRAINT FK_284B31F1371F3A6E FOREIGN KEY (unlocked_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE348F7DB25B');
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE3423EDC87');
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE34DF9D8D15');
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE34896DBBDE');
        $this->addSql('ALTER TABLE grade_lock DROP FOREIGN KEY FK_29EDD61EC32A47EE');
        $this->addSql('ALTER TABLE grade_lock DROP FOREIGN KEY FK_29EDD61EDF9D8D15');
        $this->addSql('ALTER TABLE grade_lock DROP FOREIGN KEY FK_29EDD61E7A88E00');
        $this->addSql('ALTER TABLE grade_unlock DROP FOREIGN KEY FK_284B31F1D823E37A');
        $this->addSql('ALTER TABLE grade_unlock DROP FOREIGN KEY FK_284B31F123EDC87');
        $this->addSql('ALTER TABLE grade_unlock DROP FOREIGN KEY FK_284B31F1DF9D8D15');
        $this->addSql('ALTER TABLE grade_unlock DROP FOREIGN KEY FK_284B31F1371F3A6E');
        $this->addSql('DROP TABLE grade');
        $this->addSql('DROP TABLE grade_lock');
        $this->addSql('DROP TABLE grade_unlock');
    }
}
