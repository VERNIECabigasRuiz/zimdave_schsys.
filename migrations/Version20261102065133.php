<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102065133 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE staff_id_details (id INT AUTO_INCREMENT NOT NULL, emergency_name VARCHAR(100) DEFAULT NULL, emergency_contact VARCHAR(60) DEFAULT NULL, gsis_policy_no VARCHAR(30) DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_4AE9B22BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE staff_id_details ADD CONSTRAINT FK_4AE9B22BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE staff_id_details DROP FOREIGN KEY FK_4AE9B22BA76ED395');
        $this->addSql('DROP TABLE staff_id_details');
    }
}
