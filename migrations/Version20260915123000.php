<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add remediation fields for SLA tracking, escalation, secure password reset tokens, login lockout, and ticket counters.';
    }

    public function up(Schema $schema): void
    {
        $isPg = $this->platform instanceof PostgreSQLPlatform;

        if ($isPg) {
            $this->addSql('ALTER TABLE users ADD password_change_required BOOLEAN NOT NULL DEFAULT FALSE');
            $this->addSql('ALTER TABLE users ADD failed_login_count INTEGER NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE users ADD locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

            $this->addSql('ALTER TABLE tickets ADD accepted_by_id UUID DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD first_responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD sla_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD sla_paused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD total_paused_seconds INTEGER NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE tickets ADD escalated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD escalated_by_id UUID DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_tickets_sla_due_at ON tickets (sla_due_at)');
            $this->addSql('CREATE INDEX idx_tickets_escalated_at ON tickets (escalated_at)');
            $this->addSql('CREATE INDEX IDX_TICKETS_ACCEPTED_BY ON tickets (accepted_by_id)');
            $this->addSql('CREATE INDEX IDX_TICKETS_ESCALATED_BY ON tickets (escalated_by_id)');

            $this->addSql('CREATE TABLE password_reset_tokens (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(16) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_PASSWORD_RESET_TOKEN_HASH ON password_reset_tokens (token_hash)');
            $this->addSql('CREATE INDEX idx_password_reset_token_hash ON password_reset_tokens (token_hash)');
            $this->addSql('CREATE INDEX idx_password_reset_expires_at ON password_reset_tokens (expires_at)');
            $this->addSql('CREATE INDEX IDX_PASSWORD_RESET_USER ON password_reset_tokens (user_id)');
            $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_PASSWORD_RESET_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

            $this->addSql('CREATE TABLE ticket_counters (date_key VARCHAR(8) NOT NULL, next_number INTEGER NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(date_key))');
        } else {
            // SQLite (local dev)
            $this->addSql('ALTER TABLE users ADD COLUMN password_change_required BOOLEAN NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE users ADD COLUMN failed_login_count INTEGER NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL');

            $this->addSql('ALTER TABLE tickets ADD COLUMN accepted_by_id BLOB DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD COLUMN accepted_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD COLUMN first_responded_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD COLUMN sla_started_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD COLUMN sla_paused_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD COLUMN total_paused_seconds INTEGER NOT NULL DEFAULT 0');
            $this->addSql('ALTER TABLE tickets ADD COLUMN escalated_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE tickets ADD COLUMN escalated_by_id BLOB DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_tickets_sla_due_at ON tickets (sla_due_at)');
            $this->addSql('CREATE INDEX idx_tickets_escalated_at ON tickets (escalated_at)');
            $this->addSql('CREATE INDEX IDX_TICKETS_ACCEPTED_BY ON tickets (accepted_by_id)');
            $this->addSql('CREATE INDEX IDX_TICKETS_ESCALATED_BY ON tickets (escalated_by_id)');

            $this->addSql('CREATE TABLE password_reset_tokens (id BLOB NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(16) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id BLOB NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_PASSWORD_RESET_TOKEN_HASH ON password_reset_tokens (token_hash)');
            $this->addSql('CREATE INDEX idx_password_reset_token_hash ON password_reset_tokens (token_hash)');
            $this->addSql('CREATE INDEX idx_password_reset_expires_at ON password_reset_tokens (expires_at)');
            $this->addSql('CREATE INDEX IDX_PASSWORD_RESET_USER ON password_reset_tokens (user_id)');

            $this->addSql('CREATE TABLE ticket_counters (date_key VARCHAR(8) NOT NULL, next_number INTEGER NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(date_key))');
        }
    }

    public function down(Schema $schema): void
    {
        $isPg = $this->platform instanceof PostgreSQLPlatform;

        $this->addSql('DROP TABLE ticket_counters');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP INDEX IDX_TICKETS_ESCALATED_BY');
        $this->addSql('DROP INDEX IDX_TICKETS_ACCEPTED_BY');
        $this->addSql('DROP INDEX idx_tickets_escalated_at');
        $this->addSql('DROP INDEX idx_tickets_sla_due_at');

        if ($isPg) {
            $this->addSql('ALTER TABLE tickets DROP escalated_by_id');
            $this->addSql('ALTER TABLE tickets DROP escalated_at');
            $this->addSql('ALTER TABLE tickets DROP total_paused_seconds');
            $this->addSql('ALTER TABLE tickets DROP sla_paused_at');
            $this->addSql('ALTER TABLE tickets DROP sla_started_at');
            $this->addSql('ALTER TABLE tickets DROP first_responded_at');
            $this->addSql('ALTER TABLE tickets DROP accepted_at');
            $this->addSql('ALTER TABLE tickets DROP accepted_by_id');
            $this->addSql('ALTER TABLE users DROP locked_until');
            $this->addSql('ALTER TABLE users DROP failed_login_count');
            $this->addSql('ALTER TABLE users DROP password_change_required');
        } else {
            $this->addSql('ALTER TABLE tickets DROP COLUMN escalated_by_id');
            $this->addSql('ALTER TABLE tickets DROP COLUMN escalated_at');
            $this->addSql('ALTER TABLE tickets DROP COLUMN total_paused_seconds');
            $this->addSql('ALTER TABLE tickets DROP COLUMN sla_paused_at');
            $this->addSql('ALTER TABLE tickets DROP COLUMN sla_started_at');
            $this->addSql('ALTER TABLE tickets DROP COLUMN first_responded_at');
            $this->addSql('ALTER TABLE tickets DROP COLUMN accepted_at');
            $this->addSql('ALTER TABLE tickets DROP COLUMN accepted_by_id');
            $this->addSql('ALTER TABLE users DROP COLUMN locked_until');
            $this->addSql('ALTER TABLE users DROP COLUMN failed_login_count');
            $this->addSql('ALTER TABLE users DROP COLUMN password_change_required');
        }
    }
}