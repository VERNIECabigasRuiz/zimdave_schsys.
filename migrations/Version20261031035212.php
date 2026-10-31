<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031035212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE card_slip_comment (id INT AUTO_INCREMENT NOT NULL, codes JSON NOT NULL, custom_text VARCHAR(300) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, updated_by_id INT DEFAULT NULL, enrollment_id INT NOT NULL, grading_period_id INT NOT NULL, UNIQUE INDEX uniq_card_slip_comment (enrollment_id, grading_period_id), INDEX IDX_3ABDBF86896DBBDE (updated_by_id), INDEX IDX_3ABDBF868F7DB25B (enrollment_id), INDEX IDX_3ABDBF86DF9D8D15 (grading_period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE card_slip_comment ADD CONSTRAINT FK_3ABDBF86896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE card_slip_comment ADD CONSTRAINT FK_3ABDBF868F7DB25B FOREIGN KEY (enrollment_id) REFERENCES enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE card_slip_comment ADD CONSTRAINT FK_3ABDBF86DF9D8D15 FOREIGN KEY (grading_period_id) REFERENCES grading_period (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE card_slip_comment DROP FOREIGN KEY FK_3ABDBF86896DBBDE');
        $this->addSql('ALTER TABLE card_slip_comment DROP FOREIGN KEY FK_3ABDBF868F7DB25B');
        $this->addSql('ALTER TABLE card_slip_comment DROP FOREIGN KEY FK_3ABDBF86DF9D8D15');
        $this->addSql('DROP TABLE card_slip_comment');
    }
}
