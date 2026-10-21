<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016113603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PDS sections III-VIII: education, eligibility, work experience, L&D, voluntary work, other information';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pds_education (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, level VARCHAR(20) NOT NULL, school_name VARCHAR(200) NOT NULL, course VARCHAR(200) DEFAULT NULL, year_from INT DEFAULT NULL, year_to INT DEFAULT NULL, highest_level VARCHAR(100) DEFAULT NULL, year_graduated INT DEFAULT NULL, honors VARCHAR(200) DEFAULT NULL, sheet_id INT NOT NULL, INDEX IDX_8C08CE3A8B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pds_eligibility (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, name VARCHAR(200) NOT NULL, rating VARCHAR(20) DEFAULT NULL, exam_date DATE DEFAULT NULL, exam_place VARCHAR(200) DEFAULT NULL, license_no VARCHAR(50) DEFAULT NULL, license_valid_until DATE DEFAULT NULL, sheet_id INT NOT NULL, INDEX IDX_56D533B28B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pds_training (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, title VARCHAR(255) NOT NULL, date_from DATE NOT NULL, date_to DATE DEFAULT NULL, hours NUMERIC(6, 2) DEFAULT NULL, type VARCHAR(50) DEFAULT NULL, sponsor VARCHAR(255) DEFAULT NULL, sheet_id INT NOT NULL, INDEX IDX_BF3747FB8B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pds_voluntary_work (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, organization VARCHAR(255) NOT NULL, date_from DATE DEFAULT NULL, date_to DATE DEFAULT NULL, hours NUMERIC(6, 2) DEFAULT NULL, nature VARCHAR(150) DEFAULT NULL, sheet_id INT NOT NULL, INDEX IDX_FA9157928B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pds_work_experience (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, date_from DATE NOT NULL, date_to DATE DEFAULT NULL, present TINYINT NOT NULL, position_title VARCHAR(200) NOT NULL, office VARCHAR(255) NOT NULL, monthly_salary NUMERIC(12, 2) DEFAULT NULL, salary_grade VARCHAR(10) DEFAULT NULL, appointment_status VARCHAR(50) DEFAULT NULL, government_service TINYINT NOT NULL, sheet_id INT NOT NULL, INDEX IDX_F61822918B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pds_education ADD CONSTRAINT FK_8C08CE3A8B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pds_eligibility ADD CONSTRAINT FK_56D533B28B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pds_training ADD CONSTRAINT FK_BF3747FB8B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pds_voluntary_work ADD CONSTRAINT FK_FA9157928B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pds_work_experience ADD CONSTRAINT FK_F61822918B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personal_data_sheet ADD skills JSON NOT NULL, ADD distinctions JSON NOT NULL, ADD memberships JSON NOT NULL');
        // Sheets saved before this migration start with empty lists
        $this->addSql("UPDATE personal_data_sheet SET skills = '[]', distinctions = '[]', memberships = '[]'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pds_education DROP FOREIGN KEY FK_8C08CE3A8B1206A5');
        $this->addSql('ALTER TABLE pds_eligibility DROP FOREIGN KEY FK_56D533B28B1206A5');
        $this->addSql('ALTER TABLE pds_training DROP FOREIGN KEY FK_BF3747FB8B1206A5');
        $this->addSql('ALTER TABLE pds_voluntary_work DROP FOREIGN KEY FK_FA9157928B1206A5');
        $this->addSql('ALTER TABLE pds_work_experience DROP FOREIGN KEY FK_F61822918B1206A5');
        $this->addSql('DROP TABLE pds_education');
        $this->addSql('DROP TABLE pds_eligibility');
        $this->addSql('DROP TABLE pds_training');
        $this->addSql('DROP TABLE pds_voluntary_work');
        $this->addSql('DROP TABLE pds_work_experience');
        $this->addSql('ALTER TABLE personal_data_sheet DROP skills, DROP distinctions, DROP memberships');
    }
}
