<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029091126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE school_day_off (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, reason VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, school_id INT NOT NULL, school_year_id INT NOT NULL, created_by_id INT NOT NULL, UNIQUE INDEX uniq_school_day_off (school_id, date), INDEX IDX_4340F4C32A47EE (school_id), INDEX IDX_4340F4D2EECC3F (school_year_id), INDEX IDX_4340F4B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE school_day_off ADD CONSTRAINT FK_4340F4C32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_day_off ADD CONSTRAINT FK_4340F4D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_day_off ADD CONSTRAINT FK_4340F4B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE school_day_off DROP FOREIGN KEY FK_4340F4C32A47EE');
        $this->addSql('ALTER TABLE school_day_off DROP FOREIGN KEY FK_4340F4D2EECC3F');
        $this->addSql('ALTER TABLE school_day_off DROP FOREIGN KEY FK_4340F4B03A8386');
        $this->addSql('DROP TABLE school_day_off');
    }
}
