<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031010243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE grade_opening (id INT AUTO_INCREMENT NOT NULL, opened_at DATETIME NOT NULL, school_id INT NOT NULL, grading_period_id INT NOT NULL, opened_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_grade_opening (school_id, grading_period_id), INDEX IDX_F4784621C32A47EE (school_id), INDEX IDX_F4784621DF9D8D15 (grading_period_id), INDEX IDX_F4784621AB159F5 (opened_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE grade_opening ADD CONSTRAINT FK_F4784621C32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_opening ADD CONSTRAINT FK_F4784621DF9D8D15 FOREIGN KEY (grading_period_id) REFERENCES grading_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE grade_opening ADD CONSTRAINT FK_F4784621AB159F5 FOREIGN KEY (opened_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        // every school starts with the periods that are open today
        $this->addSql('INSERT INTO grade_opening (school_id, grading_period_id, opened_by_id, opened_at) SELECT s.id, gp.id, NULL, NOW() FROM school s CROSS JOIN grading_period gp WHERE gp.open = 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE grade_opening DROP FOREIGN KEY FK_F4784621C32A47EE');
        $this->addSql('ALTER TABLE grade_opening DROP FOREIGN KEY FK_F4784621DF9D8D15');
        $this->addSql('ALTER TABLE grade_opening DROP FOREIGN KEY FK_F4784621AB159F5');
        $this->addSql('DROP TABLE grade_opening');
    }
}
