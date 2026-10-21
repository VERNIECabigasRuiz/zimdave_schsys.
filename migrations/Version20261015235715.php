<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015235715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE school_assignment (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, role VARCHAR(30) NOT NULL, school_id INT NOT NULL, school_year_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_8564C448A76ED395D2EECC3F (user_id, school_year_id), INDEX IDX_8564C448C32A47EE (school_id), INDEX IDX_8564C448D2EECC3F (school_year_id), INDEX IDX_8564C448A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE school_assignment ADD CONSTRAINT FK_8564C448C32A47EE FOREIGN KEY (school_id) REFERENCES school (id)');
        $this->addSql('ALTER TABLE school_assignment ADD CONSTRAINT FK_8564C448D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_assignment ADD CONSTRAINT FK_8564C448A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE school_assignment DROP FOREIGN KEY FK_8564C448C32A47EE');
        $this->addSql('ALTER TABLE school_assignment DROP FOREIGN KEY FK_8564C448D2EECC3F');
        $this->addSql('ALTER TABLE school_assignment DROP FOREIGN KEY FK_8564C448A76ED395');
        $this->addSql('DROP TABLE school_assignment');
    }
}
