<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027065342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Teaching loads and schedule entries';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE schedule_entry (id INT AUTO_INCREMENT NOT NULL, day SMALLINT NOT NULL, term SMALLINT NOT NULL, load_id INT NOT NULL, section_id INT NOT NULL, teacher_id INT NOT NULL, school_year_id INT NOT NULL, period_id INT NOT NULL, UNIQUE INDEX uniq_entry_section_slot (section_id, day, period_id, term), UNIQUE INDEX uniq_entry_teacher_slot (teacher_id, school_year_id, day, period_id, term), INDEX IDX_D7785D2CA1CE66EB (load_id), INDEX IDX_D7785D2CD823E37A (section_id), INDEX IDX_D7785D2C41807E1D (teacher_id), INDEX IDX_D7785D2CD2EECC3F (school_year_id), INDEX IDX_D7785D2CEC8B7ADE (period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teaching_load (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, section_id INT NOT NULL, subject_id INT NOT NULL, teacher_id INT NOT NULL, UNIQUE INDEX uniq_load_section_subject (section_id, subject_id), INDEX IDX_36244E6ED823E37A (section_id), INDEX IDX_36244E6E23EDC87 (subject_id), INDEX IDX_36244E6E41807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_D7785D2CA1CE66EB FOREIGN KEY (load_id) REFERENCES teaching_load (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_D7785D2CD823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_D7785D2C41807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_D7785D2CD2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_D7785D2CEC8B7ADE FOREIGN KEY (period_id) REFERENCES class_period (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE teaching_load ADD CONSTRAINT FK_36244E6ED823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE teaching_load ADD CONSTRAINT FK_36244E6E23EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE teaching_load ADD CONSTRAINT FK_36244E6E41807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_D7785D2CA1CE66EB');
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_D7785D2CD823E37A');
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_D7785D2C41807E1D');
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_D7785D2CD2EECC3F');
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_D7785D2CEC8B7ADE');
        $this->addSql('ALTER TABLE teaching_load DROP FOREIGN KEY FK_36244E6ED823E37A');
        $this->addSql('ALTER TABLE teaching_load DROP FOREIGN KEY FK_36244E6E23EDC87');
        $this->addSql('ALTER TABLE teaching_load DROP FOREIGN KEY FK_36244E6E41807E1D');
        $this->addSql('DROP TABLE schedule_entry');
        $this->addSql('DROP TABLE teaching_load');
    }
}
