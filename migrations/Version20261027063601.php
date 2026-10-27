<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027063601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Class periods: the slots of the school day per school year';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE class_period (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, starts_at TIME NOT NULL, ends_at TIME NOT NULL, break_label VARCHAR(40) DEFAULT NULL, school_id INT NOT NULL, school_year_id INT NOT NULL, INDEX IDX_AE45C76C32A47EE (school_id), INDEX IDX_AE45C76D2EECC3F (school_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE class_period ADD CONSTRAINT FK_AE45C76C32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE class_period ADD CONSTRAINT FK_AE45C76D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE class_period DROP FOREIGN KEY FK_AE45C76C32A47EE');
        $this->addSql('ALTER TABLE class_period DROP FOREIGN KEY FK_AE45C76D2EECC3F');
        $this->addSql('DROP TABLE class_period');
    }
}
