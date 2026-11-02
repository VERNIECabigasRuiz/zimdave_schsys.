<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030020710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE nutrition_assessment (id INT AUTO_INCREMENT NOT NULL, period VARCHAR(10) NOT NULL, weighed_on DATE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, section_id INT NOT NULL, created_by_id INT NOT NULL, UNIQUE INDEX uniq_nutrition_assessment (section_id, period), INDEX IDX_50CA91E7D823E37A (section_id), INDEX IDX_50CA91E7B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE nutrition_measurement (id INT AUTO_INCREMENT NOT NULL, weight_kg NUMERIC(5, 2) DEFAULT NULL, height_m NUMERIC(3, 2) DEFAULT NULL, remarks VARCHAR(100) DEFAULT NULL, assessment_id INT NOT NULL, enrollment_id INT NOT NULL, UNIQUE INDEX uniq_nutrition_measurement (assessment_id, enrollment_id), INDEX IDX_422C465ADD3DD5F1 (assessment_id), INDEX IDX_422C465A8F7DB25B (enrollment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sf8_submission (id INT AUTO_INCREMENT NOT NULL, period VARCHAR(10) NOT NULL, submitted_at DATETIME NOT NULL, section_id INT NOT NULL, submitted_by_id INT NOT NULL, UNIQUE INDEX uniq_sf8_submission (section_id, period), INDEX IDX_284FB147D823E37A (section_id), INDEX IDX_284FB14779F7D87D (submitted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE nutrition_assessment ADD CONSTRAINT FK_50CA91E7D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE nutrition_assessment ADD CONSTRAINT FK_50CA91E7B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE nutrition_measurement ADD CONSTRAINT FK_422C465ADD3DD5F1 FOREIGN KEY (assessment_id) REFERENCES nutrition_assessment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE nutrition_measurement ADD CONSTRAINT FK_422C465A8F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sf8_submission ADD CONSTRAINT FK_284FB147D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sf8_submission ADD CONSTRAINT FK_284FB14779F7D87D FOREIGN KEY (submitted_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE school ADD sf8_certifier_name VARCHAR(100) DEFAULT NULL, ADD sf8_certifier_position VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE nutrition_assessment DROP FOREIGN KEY FK_50CA91E7D823E37A');
        $this->addSql('ALTER TABLE nutrition_assessment DROP FOREIGN KEY FK_50CA91E7B03A8386');
        $this->addSql('ALTER TABLE nutrition_measurement DROP FOREIGN KEY FK_422C465ADD3DD5F1');
        $this->addSql('ALTER TABLE nutrition_measurement DROP FOREIGN KEY FK_422C465A8F7DB25B');
        $this->addSql('ALTER TABLE sf8_submission DROP FOREIGN KEY FK_284FB147D823E37A');
        $this->addSql('ALTER TABLE sf8_submission DROP FOREIGN KEY FK_284FB14779F7D87D');
        $this->addSql('DROP TABLE nutrition_assessment');
        $this->addSql('DROP TABLE nutrition_measurement');
        $this->addSql('DROP TABLE sf8_submission');
        $this->addSql('ALTER TABLE school DROP sf8_certifier_name, DROP sf8_certifier_position');
    }
}
