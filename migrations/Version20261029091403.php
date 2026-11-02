<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029091403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attendance_mark (id INT AUTO_INCREMENT NOT NULL, mark VARCHAR(10) NOT NULL, roll_call_id INT NOT NULL, enrollment_id INT NOT NULL, UNIQUE INDEX uniq_attendance_mark (roll_call_id, enrollment_id), INDEX IDX_5EAA2F0440C6888C (roll_call_id), INDEX IDX_5EAA2F048F7DB25B (enrollment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roll_call (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, taken_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, section_id INT NOT NULL, taken_by_id INT NOT NULL, UNIQUE INDEX uniq_roll_call_section_date (section_id, date), INDEX IDX_553E0C62D823E37A (section_id), INDEX IDX_553E0C6217F014F6 (taken_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sf2_submission (id INT AUTO_INCREMENT NOT NULL, month DATE NOT NULL, submitted_at DATETIME NOT NULL, section_id INT NOT NULL, submitted_by_id INT NOT NULL, UNIQUE INDEX uniq_sf2_submission (section_id, month), INDEX IDX_C2785926D823E37A (section_id), INDEX IDX_C278592679F7D87D (submitted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE attendance_mark ADD CONSTRAINT FK_5EAA2F0440C6888C FOREIGN KEY (roll_call_id) REFERENCES roll_call (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attendance_mark ADD CONSTRAINT FK_5EAA2F048F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roll_call ADD CONSTRAINT FK_553E0C62D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roll_call ADD CONSTRAINT FK_553E0C6217F014F6 FOREIGN KEY (taken_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE sf2_submission ADD CONSTRAINT FK_C2785926D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sf2_submission ADD CONSTRAINT FK_C278592679F7D87D FOREIGN KEY (submitted_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_mark DROP FOREIGN KEY FK_5EAA2F0440C6888C');
        $this->addSql('ALTER TABLE attendance_mark DROP FOREIGN KEY FK_5EAA2F048F7DB25B');
        $this->addSql('ALTER TABLE roll_call DROP FOREIGN KEY FK_553E0C62D823E37A');
        $this->addSql('ALTER TABLE roll_call DROP FOREIGN KEY FK_553E0C6217F014F6');
        $this->addSql('ALTER TABLE sf2_submission DROP FOREIGN KEY FK_C2785926D823E37A');
        $this->addSql('ALTER TABLE sf2_submission DROP FOREIGN KEY FK_C278592679F7D87D');
        $this->addSql('DROP TABLE attendance_mark');
        $this->addSql('DROP TABLE roll_call');
        $this->addSql('DROP TABLE sf2_submission');
    }
}
