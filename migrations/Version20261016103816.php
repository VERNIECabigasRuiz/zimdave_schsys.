<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016103816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal Data Sheet (sections I-II), children, profile photo and school profile fields';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pds_child (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(150) NOT NULL, date_of_birth DATE DEFAULT NULL, position INT NOT NULL, sheet_id INT NOT NULL, INDEX IDX_86C2AA9A8B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personal_data_sheet (id INT AUTO_INCREMENT NOT NULL, surname VARCHAR(80) DEFAULT NULL, first_name VARCHAR(80) DEFAULT NULL, name_extension VARCHAR(10) DEFAULT NULL, middle_name VARCHAR(80) DEFAULT NULL, date_of_birth DATE DEFAULT NULL, place_of_birth VARCHAR(150) DEFAULT NULL, sex_at_birth VARCHAR(10) DEFAULT NULL, civil_status VARCHAR(10) DEFAULT NULL, civil_status_other VARCHAR(50) DEFAULT NULL, height NUMERIC(4, 2) DEFAULT NULL, weight NUMERIC(5, 2) DEFAULT NULL, blood_type VARCHAR(5) DEFAULT NULL, umid_no VARCHAR(30) DEFAULT NULL, pagibig_no VARCHAR(30) DEFAULT NULL, philhealth_no VARCHAR(30) DEFAULT NULL, philsys_pcn VARCHAR(30) DEFAULT NULL, tin VARCHAR(30) DEFAULT NULL, agency_employee_no VARCHAR(30) DEFAULT NULL, citizenship VARCHAR(10) DEFAULT NULL, dual_citizenship_by VARCHAR(20) DEFAULT NULL, dual_citizenship_country VARCHAR(80) DEFAULT NULL, res_house_no VARCHAR(50) DEFAULT NULL, res_street VARCHAR(100) DEFAULT NULL, res_subdivision VARCHAR(100) DEFAULT NULL, res_barangay VARCHAR(100) DEFAULT NULL, res_city VARCHAR(100) DEFAULT NULL, res_province VARCHAR(100) DEFAULT NULL, res_zip VARCHAR(4) DEFAULT NULL, perm_same_as_residential TINYINT NOT NULL, perm_house_no VARCHAR(50) DEFAULT NULL, perm_street VARCHAR(100) DEFAULT NULL, perm_subdivision VARCHAR(100) DEFAULT NULL, perm_barangay VARCHAR(100) DEFAULT NULL, perm_city VARCHAR(100) DEFAULT NULL, perm_province VARCHAR(100) DEFAULT NULL, perm_zip VARCHAR(4) DEFAULT NULL, telephone_no VARCHAR(30) DEFAULT NULL, mobile_no VARCHAR(20) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, spouse_surname VARCHAR(80) DEFAULT NULL, spouse_first_name VARCHAR(80) DEFAULT NULL, spouse_name_extension VARCHAR(10) DEFAULT NULL, spouse_middle_name VARCHAR(80) DEFAULT NULL, spouse_occupation VARCHAR(100) DEFAULT NULL, spouse_employer VARCHAR(150) DEFAULT NULL, spouse_business_address VARCHAR(255) DEFAULT NULL, spouse_telephone VARCHAR(30) DEFAULT NULL, father_surname VARCHAR(80) DEFAULT NULL, father_first_name VARCHAR(80) DEFAULT NULL, father_name_extension VARCHAR(10) DEFAULT NULL, father_middle_name VARCHAR(80) DEFAULT NULL, mother_maiden_surname VARCHAR(80) DEFAULT NULL, mother_first_name VARCHAR(80) DEFAULT NULL, mother_middle_name VARCHAR(80) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_8953FADDA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pds_child ADD CONSTRAINT FK_86C2AA9A8B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personal_data_sheet ADD CONSTRAINT FK_8953FADDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school ADD logo_path VARCHAR(255) DEFAULT NULL, ADD contact_number VARCHAR(30) DEFAULT NULL, ADD email VARCHAR(180) DEFAULT NULL, ADD facebook_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD photo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pds_child DROP FOREIGN KEY FK_86C2AA9A8B1206A5');
        $this->addSql('ALTER TABLE personal_data_sheet DROP FOREIGN KEY FK_8953FADDA76ED395');
        $this->addSql('DROP TABLE pds_child');
        $this->addSql('DROP TABLE personal_data_sheet');
        $this->addSql('ALTER TABLE school DROP logo_path, DROP contact_number, DROP email, DROP facebook_url');
        $this->addSql('ALTER TABLE `user` DROP photo_path');
    }
}
