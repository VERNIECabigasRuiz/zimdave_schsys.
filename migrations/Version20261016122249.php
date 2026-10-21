<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016122249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PDS questions 34-40, references (item 41) and government-issued ID';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pds_reference (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, name VARCHAR(150) NOT NULL, address VARCHAR(255) NOT NULL, contact VARCHAR(100) NOT NULL, sheet_id INT NOT NULL, INDEX IDX_F9A1D9FB8B1206A5 (sheet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pds_reference ADD CONSTRAINT FK_F9A1D9FB8B1206A5 FOREIGN KEY (sheet_id) REFERENCES personal_data_sheet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personal_data_sheet ADD q34a TINYINT DEFAULT NULL, ADD q34b TINYINT DEFAULT NULL, ADD q34_details VARCHAR(255) DEFAULT NULL, ADD q35a TINYINT DEFAULT NULL, ADD q35a_details VARCHAR(255) DEFAULT NULL, ADD q35b TINYINT DEFAULT NULL, ADD q35b_details VARCHAR(255) DEFAULT NULL, ADD q35b_date_filed DATE DEFAULT NULL, ADD q35b_status VARCHAR(100) DEFAULT NULL, ADD q36 TINYINT DEFAULT NULL, ADD q36_details VARCHAR(255) DEFAULT NULL, ADD q37 TINYINT DEFAULT NULL, ADD q37_details VARCHAR(255) DEFAULT NULL, ADD q38a TINYINT DEFAULT NULL, ADD q38a_details VARCHAR(255) DEFAULT NULL, ADD q38b TINYINT DEFAULT NULL, ADD q38b_details VARCHAR(255) DEFAULT NULL, ADD q39 TINYINT DEFAULT NULL, ADD q39_details VARCHAR(100) DEFAULT NULL, ADD q40a TINYINT DEFAULT NULL, ADD q40a_details VARCHAR(150) DEFAULT NULL, ADD q40b TINYINT DEFAULT NULL, ADD q40b_details VARCHAR(50) DEFAULT NULL, ADD q40c TINYINT DEFAULT NULL, ADD q40c_details VARCHAR(50) DEFAULT NULL, ADD gov_id_type VARCHAR(80) DEFAULT NULL, ADD gov_id_number VARCHAR(80) DEFAULT NULL, ADD gov_id_issued VARCHAR(150) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pds_reference DROP FOREIGN KEY FK_F9A1D9FB8B1206A5');
        $this->addSql('DROP TABLE pds_reference');
        $this->addSql('ALTER TABLE personal_data_sheet DROP q34a, DROP q34b, DROP q34_details, DROP q35a, DROP q35a_details, DROP q35b, DROP q35b_details, DROP q35b_date_filed, DROP q35b_status, DROP q36, DROP q36_details, DROP q37, DROP q37_details, DROP q38a, DROP q38a_details, DROP q38b, DROP q38b_details, DROP q39, DROP q39_details, DROP q40a, DROP q40a_details, DROP q40b, DROP q40b_details, DROP q40c, DROP q40c_details, DROP gov_id_type, DROP gov_id_number, DROP gov_id_issued');
    }
}
