<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016002140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_7363AA21D2EECC3F1C81E107 ON grading_period');
        $this->addSql('ALTER TABLE grading_period ADD start_date DATE DEFAULT NULL, ADD end_date DATE DEFAULT NULL, CHANGE quarter number SMALLINT NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7363AA21D2EECC3F96901F54 ON grading_period (school_year_id, number)');
        $this->addSql('ALTER TABLE school_year ADD grading_system VARCHAR(10) NOT NULL');
        // School years created before this change were built with four quarters
        $this->addSql("UPDATE school_year SET grading_system = 'quarters'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_7363AA21D2EECC3F96901F54 ON grading_period');
        $this->addSql('ALTER TABLE grading_period DROP start_date, DROP end_date, CHANGE number quarter SMALLINT NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7363AA21D2EECC3F1C81E107 ON grading_period (school_year_id, quarter)');
        $this->addSql('ALTER TABLE school_year DROP grading_system');
    }
}
