<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105161419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove AI lesson plan drafts: the ai_draft table, lesson_plan.drafted_at and the plan monthly draft limit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_draft DROP FOREIGN KEY FK_1ACBADD1A76ED395');
        $this->addSql('ALTER TABLE ai_draft DROP FOREIGN KEY FK_1ACBADD1C32A47EE');
        $this->addSql('DROP TABLE ai_draft');
        $this->addSql('ALTER TABLE lesson_plan DROP drafted_at');
        $this->addSql('ALTER TABLE plan DROP ai_drafts_per_teacher_monthly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_draft (id INT AUTO_INCREMENT NOT NULL, model VARCHAR(60) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, school_id INT NOT NULL, INDEX idx_ai_draft_user_at (user_id, created_at), INDEX IDX_1ACBADD1A76ED395 (user_id), INDEX IDX_1ACBADD1C32A47EE (school_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_draft ADD CONSTRAINT FK_1ACBADD1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_draft ADD CONSTRAINT FK_1ACBADD1C32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_plan ADD drafted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE plan ADD ai_drafts_per_teacher_monthly INT DEFAULT 0 NOT NULL');
    }
}
