<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029045258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE learner ADD mother_tongue VARCHAR(50) DEFAULT NULL, ADD ip_group VARCHAR(50) DEFAULT NULL, ADD religion VARCHAR(50) DEFAULT NULL, ADD address_street VARCHAR(100) DEFAULT NULL, ADD address_barangay VARCHAR(50) DEFAULT NULL, ADD address_city VARCHAR(50) DEFAULT NULL, ADD address_province VARCHAR(50) DEFAULT NULL, ADD father_last_name VARCHAR(50) DEFAULT NULL, ADD father_first_name VARCHAR(50) DEFAULT NULL, ADD father_middle_name VARCHAR(50) DEFAULT NULL, ADD mother_last_name VARCHAR(50) DEFAULT NULL, ADD mother_first_name VARCHAR(50) DEFAULT NULL, ADD mother_middle_name VARCHAR(50) DEFAULT NULL, ADD guardian_name VARCHAR(100) DEFAULT NULL, ADD guardian_relationship VARCHAR(30) DEFAULT NULL, ADD contact_number VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE learner DROP mother_tongue, DROP ip_group, DROP religion, DROP address_street, DROP address_barangay, DROP address_city, DROP address_province, DROP father_last_name, DROP father_first_name, DROP father_middle_name, DROP mother_last_name, DROP mother_first_name, DROP mother_middle_name, DROP guardian_name, DROP guardian_relationship, DROP contact_number');
    }
}
