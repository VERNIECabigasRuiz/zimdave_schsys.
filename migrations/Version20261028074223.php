<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028074223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create learner, enrollment and transfer_request tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE enrollment (id INT AUTO_INCREMENT NOT NULL, enrolled_at DATETIME NOT NULL, learner_id INT NOT NULL, school_year_id INT NOT NULL, school_id INT NOT NULL, section_id INT NOT NULL, enrolled_by_id INT NOT NULL, UNIQUE INDEX uniq_enrollment_learner_year (learner_id, school_year_id), INDEX IDX_DBDCD7E16209CB66 (learner_id), INDEX IDX_DBDCD7E1D2EECC3F (school_year_id), INDEX IDX_DBDCD7E1C32A47EE (school_id), INDEX IDX_DBDCD7E1D823E37A (section_id), INDEX IDX_DBDCD7E121EE0EA4 (enrolled_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE learner (id INT AUTO_INCREMENT NOT NULL, lrn VARCHAR(12) NOT NULL, last_name VARCHAR(50) NOT NULL, first_name VARCHAR(50) NOT NULL, middle_name VARCHAR(50) DEFAULT NULL, extension VARCHAR(10) DEFAULT NULL, sex VARCHAR(10) NOT NULL, birth_date DATE NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_8EF383498FBC7D (lrn), UNIQUE INDEX UNIQ_8EF3834A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transfer_request (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, learner_id INT NOT NULL, enrollment_id INT NOT NULL, from_school_id INT NOT NULL, from_section_id INT NOT NULL, to_section_id INT NOT NULL, requested_by_id INT NOT NULL, decided_by_id INT DEFAULT NULL, INDEX IDX_8422FDD46209CB66 (learner_id), INDEX IDX_8422FDD48F7DB25B (enrollment_id), INDEX IDX_8422FDD4D3B6D254 (from_school_id), INDEX IDX_8422FDD4F387257D (from_section_id), INDEX IDX_8422FDD4FF100107 (to_section_id), INDEX IDX_8422FDD44DA1E751 (requested_by_id), INDEX IDX_8422FDD4E26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E16209CB66 FOREIGN KEY (learner_id) REFERENCES learner (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E1D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E1C32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E1D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E121EE0EA4 FOREIGN KEY (enrolled_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE learner ADD CONSTRAINT FK_8EF3834A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD46209CB66 FOREIGN KEY (learner_id) REFERENCES learner (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD48F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4D3B6D254 FOREIGN KEY (from_school_id) REFERENCES school (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4F387257D FOREIGN KEY (from_section_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4FF100107 FOREIGN KEY (to_section_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD44DA1E751 FOREIGN KEY (requested_by_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4E26B496B FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE enrollment DROP FOREIGN KEY FK_DBDCD7E16209CB66');
        $this->addSql('ALTER TABLE enrollment DROP FOREIGN KEY FK_DBDCD7E1D2EECC3F');
        $this->addSql('ALTER TABLE enrollment DROP FOREIGN KEY FK_DBDCD7E1C32A47EE');
        $this->addSql('ALTER TABLE enrollment DROP FOREIGN KEY FK_DBDCD7E1D823E37A');
        $this->addSql('ALTER TABLE enrollment DROP FOREIGN KEY FK_DBDCD7E121EE0EA4');
        $this->addSql('ALTER TABLE learner DROP FOREIGN KEY FK_8EF3834A76ED395');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD46209CB66');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD48F7DB25B');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4D3B6D254');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4F387257D');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4FF100107');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD44DA1E751');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4E26B496B');
        $this->addSql('DROP TABLE enrollment');
        $this->addSql('DROP TABLE learner');
        $this->addSql('DROP TABLE transfer_request');
    }
}
