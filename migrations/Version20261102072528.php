<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102072528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE school ADD id_signature_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE school ADD CONSTRAINT FK_F99EDABBCBEE11D7 FOREIGN KEY (id_signature_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F99EDABBCBEE11D7 ON school (id_signature_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE school DROP FOREIGN KEY FK_F99EDABBCBEE11D7');
        $this->addSql('DROP INDEX IDX_F99EDABBCBEE11D7 ON school');
        $this->addSql('ALTER TABLE school DROP id_signature_by_id');
    }
}
