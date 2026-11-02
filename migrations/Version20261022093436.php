<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022093436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subject (id INT AUTO_INCREMENT NOT NULL, grade_level INT NOT NULL, code VARCHAR(20) NOT NULL, name VARCHAR(100) NOT NULL, semester SMALLINT DEFAULT NULL, track VARCHAR(20) DEFAULT NULL, strand VARCHAR(60) DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, school_id INT NOT NULL, school_year_id INT NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX uniq_subject_code (school_id, school_year_id, code), INDEX IDX_FBCE3E7AC32A47EE (school_id), INDEX IDX_FBCE3E7AD2EECC3F (school_year_id), INDEX IDX_FBCE3E7A727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subject ADD CONSTRAINT FK_FBCE3E7AC32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject ADD CONSTRAINT FK_FBCE3E7AD2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject ADD CONSTRAINT FK_FBCE3E7A727ACA70 FOREIGN KEY (parent_id) REFERENCES subject (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subject DROP FOREIGN KEY FK_FBCE3E7AC32A47EE');
        $this->addSql('ALTER TABLE subject DROP FOREIGN KEY FK_FBCE3E7AD2EECC3F');
        $this->addSql('ALTER TABLE subject DROP FOREIGN KEY FK_FBCE3E7A727ACA70');
        $this->addSql('DROP TABLE subject');
    }
}
