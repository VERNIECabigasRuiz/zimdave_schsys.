<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102043430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Service records: rows, BP number, school signatories';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE service_record (id INT AUTO_INCREMENT NOT NULL, bp_number VARCHAR(30) DEFAULT NULL, employee_id INT NOT NULL, UNIQUE INDEX UNIQ_A5F39AA78C03F15C (employee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_record_entry (id INT AUTO_INCREMENT NOT NULL, from_date DATE NOT NULL, to_date DATE DEFAULT NULL, designation VARCHAR(100) NOT NULL, status VARCHAR(50) NOT NULL, annual_salary NUMERIC(12, 2) NOT NULL, station VARCHAR(150) NOT NULL, branch VARCHAR(20) NOT NULL, lwop_from DATE DEFAULT NULL, lwop_to DATE DEFAULT NULL, separation_date DATE DEFAULT NULL, separation_cause VARCHAR(150) DEFAULT NULL, remarks VARCHAR(150) DEFAULT NULL, updated_at DATETIME NOT NULL, employee_id INT NOT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_75D649C98C03F15C (employee_id), INDEX IDX_75D649C9896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_record ADD CONSTRAINT FK_A5F39AA78C03F15C FOREIGN KEY (employee_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_record_entry ADD CONSTRAINT FK_75D649C98C03F15C FOREIGN KEY (employee_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_record_entry ADD CONSTRAINT FK_75D649C9896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE school ADD sr_prepared_by_name VARCHAR(100) DEFAULT NULL, ADD sr_prepared_by_designation VARCHAR(100) DEFAULT NULL, ADD sr_certified_by_name VARCHAR(100) DEFAULT NULL, ADD sr_certified_by_designation VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE service_record DROP FOREIGN KEY FK_A5F39AA78C03F15C');
        $this->addSql('ALTER TABLE service_record_entry DROP FOREIGN KEY FK_75D649C98C03F15C');
        $this->addSql('ALTER TABLE service_record_entry DROP FOREIGN KEY FK_75D649C9896DBBDE');
        $this->addSql('DROP TABLE service_record');
        $this->addSql('DROP TABLE service_record_entry');
        $this->addSql('ALTER TABLE school DROP sr_prepared_by_name, DROP sr_prepared_by_designation, DROP sr_certified_by_name, DROP sr_certified_by_designation');
    }
}
