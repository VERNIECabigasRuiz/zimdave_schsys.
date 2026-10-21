<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015235327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE school (id INT AUTO_INCREMENT NOT NULL, school_id VARCHAR(6) NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(20) NOT NULL, region VARCHAR(100) NOT NULL, division VARCHAR(100) NOT NULL, district VARCHAR(100) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, active TINYINT NOT NULL, UNIQUE INDEX UNIQ_F99EDABBC32A47EE (school_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE school');
    }
}
