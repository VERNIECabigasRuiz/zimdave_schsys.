<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102093432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_draft (id INT AUTO_INCREMENT NOT NULL, model VARCHAR(60) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, school_id INT NOT NULL, INDEX idx_ai_draft_user_at (user_id, created_at), INDEX IDX_1ACBADD1A76ED395 (user_id), INDEX IDX_1ACBADD1C32A47EE (school_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lesson_plan (id INT AUTO_INCREMENT NOT NULL, week_start DATE NOT NULL, topic VARCHAR(200) NOT NULL, competencies LONGTEXT NOT NULL, class_notes LONGTEXT DEFAULT NULL, `references` LONGTEXT DEFAULT NULL, ai_declaration LONGTEXT DEFAULT NULL, days JSON NOT NULL, drafted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, load_id INT NOT NULL, UNIQUE INDEX uniq_lesson_plan_load_week (load_id, week_start), INDEX IDX_E42B9D19A1CE66EB (load_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_draft ADD CONSTRAINT FK_1ACBADD1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_draft ADD CONSTRAINT FK_1ACBADD1C32A47EE FOREIGN KEY (school_id) REFERENCES school (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_plan ADD CONSTRAINT FK_E42B9D19A1CE66EB FOREIGN KEY (load_id) REFERENCES teaching_load (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE plan ADD ai_drafts_per_teacher_monthly INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE school ADD lp_checked_by_name VARCHAR(100) DEFAULT NULL, ADD lp_checked_by_position VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_draft DROP FOREIGN KEY FK_1ACBADD1A76ED395');
        $this->addSql('ALTER TABLE ai_draft DROP FOREIGN KEY FK_1ACBADD1C32A47EE');
        $this->addSql('ALTER TABLE lesson_plan DROP FOREIGN KEY FK_E42B9D19A1CE66EB');
        $this->addSql('DROP TABLE ai_draft');
        $this->addSql('DROP TABLE lesson_plan');
        $this->addSql('ALTER TABLE plan DROP ai_drafts_per_teacher_monthly');
        $this->addSql('ALTER TABLE school DROP lp_checked_by_name, DROP lp_checked_by_position');
    }
}
