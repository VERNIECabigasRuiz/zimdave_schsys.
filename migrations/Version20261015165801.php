<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015165801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin module: activity log, announcements, school years and grading periods';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity_log (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, username VARCHAR(50) NOT NULL, action VARCHAR(255) NOT NULL, INDEX IDX_FD06F6478B8E8428 (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE announcement (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, body LONGTEXT NOT NULL, audience VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_4DB9D91CF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grading_period (id INT AUTO_INCREMENT NOT NULL, open TINYINT NOT NULL, quarter SMALLINT NOT NULL, school_year_id INT NOT NULL, UNIQUE INDEX UNIQ_7363AA21D2EECC3F1C81E107 (school_year_id, quarter), INDEX IDX_7363AA21D2EECC3F (school_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_year (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(20) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, current TINYINT NOT NULL, UNIQUE INDEX UNIQ_FAAAACDA5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE grading_period ADD CONSTRAINT FK_7363AA21D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY FK_4DB9D91CF675F31B');
        $this->addSql('ALTER TABLE grading_period DROP FOREIGN KEY FK_7363AA21D2EECC3F');
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE grading_period');
        $this->addSql('DROP TABLE school_year');
    }
}
