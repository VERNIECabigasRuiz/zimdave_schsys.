<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028084314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a transfer request outlive its enrollment and cascade with its sections';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY `FK_8422FDD48F7DB25B`');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY `FK_8422FDD4F387257D`');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY `FK_8422FDD4FF100107`');
        $this->addSql('ALTER TABLE transfer_request CHANGE enrollment_id enrollment_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD48F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4F387257D FOREIGN KEY (from_section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4FF100107 FOREIGN KEY (to_section_id) REFERENCES section (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Fails once any transfer_request.enrollment_id is NULL (the column becomes NOT NULL again).
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD48F7DB25B');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4F387257D');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4FF100107');
        $this->addSql('ALTER TABLE transfer_request CHANGE enrollment_id enrollment_id INT NOT NULL');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT `FK_8422FDD48F7DB25B` FOREIGN KEY (enrollment_id) REFERENCES enrollment (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT `FK_8422FDD4F387257D` FOREIGN KEY (from_section_id) REFERENCES section (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT `FK_8422FDD4FF100107` FOREIGN KEY (to_section_id) REFERENCES section (id)');
    }
}
