<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016125321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Class sections per school and school year, with class adviser';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE section (id INT AUTO_INCREMENT NOT NULL, grade_level INT NOT NULL, name VARCHAR(60) NOT NULL, track VARCHAR(20) DEFAULT NULL, strand VARCHAR(60) DEFAULT NULL, created_at DATETIME NOT NULL, school_id INT NOT NULL, school_year_id INT NOT NULL, adviser_id INT DEFAULT NULL, UNIQUE INDEX uniq_section_name (school_id, school_year_id, grade_level, name), UNIQUE INDEX uniq_section_adviser (school_year_id, adviser_id), INDEX IDX_2D737AEFC32A47EE (school_id), INDEX IDX_2D737AEFD2EECC3F (school_year_id), INDEX IDX_2D737AEF2C63B5D6 (adviser_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFC32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFD2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEF2C63B5D6 FOREIGN KEY (adviser_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFC32A47EE');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFD2EECC3F');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEF2C63B5D6');
        $this->addSql('DROP TABLE section');
    }
}
