<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the foundational Mudi SACCO support schema (PostgreSQL edition).
 */
final class Version20260915104258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create foundational users, roles, members, departments, tickets, SLA, notifications, and audit tables.';
    }

    public function up(Schema $schema): void
    {
        $db = $this->platform;
        $isPg = $db instanceof PostgreSQLPlatform;

        if ($isPg) {
            $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
            $this->addSql('CREATE TABLE departments (id UUID NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, active BOOLEAN NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_16AEB8D477153098 ON departments (code)');
            $this->addSql('CREATE TABLE permissions (id UUID NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(140) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_2DEDCC6F77153098 ON permissions (code)');
            $this->addSql('CREATE TABLE roles (id UUID NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_B63E2EC777153098 ON roles (code)');
            $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(140) NOT NULL, user_type VARCHAR(32) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, department_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
            $this->addSql('CREATE INDEX idx_users_user_type ON users (user_type)');
            $this->addSql('CREATE INDEX IDX_1483A5E9AE80F5DF ON users (department_id)');
            $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE role_permissions (role_id UUID NOT NULL, permission_id UUID NOT NULL, PRIMARY KEY(role_id, permission_id))');
            $this->addSql('CREATE INDEX IDX_1FBA94E6D60322AC ON role_permissions (role_id)');
            $this->addSql('CREATE INDEX IDX_1FBA94E6FED90CCA ON role_permissions (permission_id)');
            $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE user_roles (user_id UUID NOT NULL, role_id UUID NOT NULL, PRIMARY KEY(user_id, role_id))');
            $this->addSql('CREATE INDEX IDX_54FCD59FA76ED395 ON user_roles (user_id)');
            $this->addSql('CREATE INDEX IDX_54FCD59FD60322AC ON user_roles (role_id)');
            $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE members (id UUID NOT NULL, member_number VARCHAR(40) NOT NULL, display_name VARCHAR(140) NOT NULL, primary_phone VARCHAR(40) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_45A0D2FFB2469D67 ON members (member_number)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_45A0D2FFA76ED395 ON members (user_id)');
            $this->addSql('CREATE INDEX idx_members_status ON members (status)');
            $this->addSql('ALTER TABLE members ADD CONSTRAINT FK_45A0D2FFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE categories (id UUID NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, default_priority VARCHAR(32) NOT NULL, active BOOLEAN NOT NULL, department_id UUID NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF3466877153098 ON categories (code)');
            $this->addSql('CREATE INDEX idx_categories_department ON categories (department_id)');
            $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE sla_rules (id UUID NOT NULL, priority VARCHAR(32) NOT NULL, response_minutes INTEGER NOT NULL, resolution_minutes INTEGER NOT NULL, escalation_minutes INTEGER NOT NULL, active BOOLEAN NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_sla_rules_priority ON sla_rules (priority)');
            $this->addSql('CREATE INDEX IDX_37C2CD1812469DE2 ON sla_rules (category_id)');
            $this->addSql('ALTER TABLE sla_rules ADD CONSTRAINT FK_37C2CD1812469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE tickets (id UUID NOT NULL, reference VARCHAR(40) NOT NULL, subject VARCHAR(180) NOT NULL, description TEXT NOT NULL, priority VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, sla_due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, member_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, department_id UUID NOT NULL, category_id UUID NOT NULL, assigned_to_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_54469DF4AEA34913 ON tickets (reference)');
            $this->addSql('CREATE INDEX idx_tickets_status ON tickets (status)');
            $this->addSql('CREATE INDEX idx_tickets_priority ON tickets (priority)');
            $this->addSql('CREATE INDEX idx_tickets_member ON tickets (member_id)');
            $this->addSql('CREATE INDEX idx_tickets_assigned_to ON tickets (assigned_to_id)');
            $this->addSql('CREATE INDEX idx_tickets_department ON tickets (department_id)');
            $this->addSql('CREATE INDEX IDX_54469DF4B03A8386 ON tickets (created_by_id)');
            $this->addSql('CREATE INDEX IDX_54469DF412469DE2 ON tickets (category_id)');
            $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF47597D3FE FOREIGN KEY (member_id) REFERENCES members (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF412469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_54469DF4F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE ticket_messages (id UUID NOT NULL, body TEXT NOT NULL, visibility VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, author_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_ticket_messages_ticket ON ticket_messages (ticket_id)');
            $this->addSql('CREATE INDEX IDX_5E6BE217F675F31B ON ticket_messages (author_id)');
            $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_5E6BE217700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_5E6BE217F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE ticket_assignments (id UUID NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, department_id UUID DEFAULT NULL, assigned_to_id UUID DEFAULT NULL, assigned_by_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_ticket_assignments_ticket ON ticket_assignments (ticket_id)');
            $this->addSql('CREATE INDEX IDX_5C1A56A2AE80F5DF ON ticket_assignments (department_id)');
            $this->addSql('CREATE INDEX IDX_5C1A56A2F4BD7827 ON ticket_assignments (assigned_to_id)');
            $this->addSql('CREATE INDEX IDX_5C1A56A26E6F1246 ON ticket_assignments (assigned_by_id)');
            $this->addSql('ALTER TABLE ticket_assignments ADD CONSTRAINT FK_5C1A56A2700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_assignments ADD CONSTRAINT FK_5C1A56A2AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_assignments ADD CONSTRAINT FK_5C1A56A2F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_assignments ADD CONSTRAINT FK_5C1A56A26E6F1246 FOREIGN KEY (assigned_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE ticket_attachments (id UUID NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INTEGER NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, message_id UUID DEFAULT NULL, uploaded_by_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_ticket_attachments_ticket ON ticket_attachments (ticket_id)');
            $this->addSql('CREATE INDEX IDX_2B54FCA9537A1329 ON ticket_attachments (message_id)');
            $this->addSql('CREATE INDEX IDX_2B54FCA9A2B28FE8 ON ticket_attachments (uploaded_by_id)');
            $this->addSql('ALTER TABLE ticket_attachments ADD CONSTRAINT FK_2B54FCA9700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_attachments ADD CONSTRAINT FK_2B54FCA9537A1329 FOREIGN KEY (message_id) REFERENCES ticket_messages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_attachments ADD CONSTRAINT FK_2B54FCA9A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE ticket_status_history (id UUID NOT NULL, from_status VARCHAR(32) DEFAULT NULL, to_status VARCHAR(32) NOT NULL, reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, changed_by_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_ticket_status_history_ticket ON ticket_status_history (ticket_id)');
            $this->addSql('CREATE INDEX IDX_D6921C0D828AD0A0 ON ticket_status_history (changed_by_id)');
            $this->addSql('ALTER TABLE ticket_status_history ADD CONSTRAINT FK_D6921C0D700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE ticket_status_history ADD CONSTRAINT FK_D6921C0D828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE notifications (id UUID NOT NULL, channel VARCHAR(32) NOT NULL, subject VARCHAR(180) NOT NULL, body TEXT NOT NULL, payload TEXT NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, recipient_id UUID NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_notifications_recipient_read ON notifications (recipient_id, read_at)');
            $this->addSql('CREATE INDEX IDX_6000B0D3E92F8F78 ON notifications (recipient_id)');
            $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE audit_logs (id UUID NOT NULL, action VARCHAR(120) NOT NULL, entity_type VARCHAR(120) NOT NULL, entity_id VARCHAR(80) DEFAULT NULL, ip_address VARCHAR(80) DEFAULT NULL, context TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor_id UUID DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id)');
            $this->addSql('CREATE INDEX idx_audit_logs_created_at ON audit_logs (created_at)');
            $this->addSql('CREATE INDEX IDX_D62F285810DAF24A ON audit_logs (actor_id)');
            $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F285810DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE TABLE satisfaction_ratings (id UUID NOT NULL, score INTEGER NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ticket_id UUID NOT NULL, member_id UUID NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_50866793700047D2 ON satisfaction_ratings (ticket_id)');
            $this->addSql('CREATE INDEX idx_satisfaction_ticket ON satisfaction_ratings (ticket_id)');
            $this->addSql('CREATE INDEX IDX_508667937597D3FE ON satisfaction_ratings (member_id)');
            $this->addSql('ALTER TABLE satisfaction_ratings ADD CONSTRAINT FK_50866793700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE satisfaction_ratings ADD CONSTRAINT FK_508667937597D3FE FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        } else {
            // SQLite (local dev)
            $this->addSql('CREATE TABLE departments (id BLOB NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, description CLOB DEFAULT NULL, active BOOLEAN NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_16AEB8D477153098 ON departments (code)');
            $this->addSql('CREATE TABLE permissions (id BLOB NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(140) NOT NULL, description CLOB DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_2DEDCC6F77153098 ON permissions (code)');
            $this->addSql('CREATE TABLE roles (id BLOB NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, description CLOB DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_B63E2EC777153098 ON roles (code)');
            $this->addSql('CREATE TABLE users (id BLOB NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(140) NOT NULL, user_type VARCHAR(32) NOT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, department_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_1483A5E9AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
            $this->addSql('CREATE INDEX idx_users_user_type ON users (user_type)');
            $this->addSql('CREATE INDEX IDX_1483A5E9AE80F5DF ON users (department_id)');
            $this->addSql('CREATE TABLE role_permissions (role_id BLOB NOT NULL, permission_id BLOB NOT NULL, PRIMARY KEY(role_id, permission_id), CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_1FBA94E6D60322AC ON role_permissions (role_id)');
            $this->addSql('CREATE INDEX IDX_1FBA94E6FED90CCA ON role_permissions (permission_id)');
            $this->addSql('CREATE TABLE user_roles (user_id BLOB NOT NULL, role_id BLOB NOT NULL, PRIMARY KEY(user_id, role_id), CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_54FCD59FA76ED395 ON user_roles (user_id)');
            $this->addSql('CREATE INDEX IDX_54FCD59FD60322AC ON user_roles (role_id)');
            $this->addSql('CREATE TABLE members (id BLOB NOT NULL, member_number VARCHAR(40) NOT NULL, display_name VARCHAR(140) NOT NULL, primary_phone VARCHAR(40) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_45A0D2FFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_45A0D2FFB2469D67 ON members (member_number)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_45A0D2FFA76ED395 ON members (user_id)');
            $this->addSql('CREATE INDEX idx_members_status ON members (status)');
            $this->addSql('CREATE TABLE categories (id BLOB NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(120) NOT NULL, description CLOB DEFAULT NULL, default_priority VARCHAR(32) NOT NULL, active BOOLEAN NOT NULL, department_id BLOB NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_3AF34668AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF3466877153098 ON categories (code)');
            $this->addSql('CREATE INDEX idx_categories_department ON categories (department_id)');
            $this->addSql('CREATE TABLE sla_rules (id BLOB NOT NULL, priority VARCHAR(32) NOT NULL, response_minutes INTEGER NOT NULL, resolution_minutes INTEGER NOT NULL, escalation_minutes INTEGER NOT NULL, active BOOLEAN NOT NULL, category_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_37C2CD1812469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_sla_rules_priority ON sla_rules (priority)');
            $this->addSql('CREATE INDEX IDX_37C2CD1812469DE2 ON sla_rules (category_id)');
            $this->addSql('CREATE TABLE tickets (id BLOB NOT NULL, reference VARCHAR(40) NOT NULL, subject VARCHAR(180) NOT NULL, description CLOB NOT NULL, priority VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, sla_due_at DATETIME DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, member_id BLOB NOT NULL, created_by_id BLOB DEFAULT NULL, department_id BLOB NOT NULL, category_id BLOB NOT NULL, assigned_to_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_54469DF47597D3FE FOREIGN KEY (member_id) REFERENCES members (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_54469DF4B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_54469DF4AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_54469DF412469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_54469DF4F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_54469DF4AEA34913 ON tickets (reference)');
            $this->addSql('CREATE INDEX idx_tickets_status ON tickets (status)');
            $this->addSql('CREATE INDEX idx_tickets_priority ON tickets (priority)');
            $this->addSql('CREATE INDEX idx_tickets_member ON tickets (member_id)');
            $this->addSql('CREATE INDEX idx_tickets_assigned_to ON tickets (assigned_to_id)');
            $this->addSql('CREATE INDEX idx_tickets_department ON tickets (department_id)');
            $this->addSql('CREATE INDEX IDX_54469DF4B03A8386 ON tickets (created_by_id)');
            $this->addSql('CREATE INDEX IDX_54469DF412469DE2 ON tickets (category_id)');
            $this->addSql('CREATE TABLE ticket_messages (id BLOB NOT NULL, body CLOB NOT NULL, visibility VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, ticket_id BLOB NOT NULL, author_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_5E6BE217700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5E6BE217F675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_ticket_messages_ticket ON ticket_messages (ticket_id)');
            $this->addSql('CREATE INDEX IDX_5E6BE217F675F31B ON ticket_messages (author_id)');
            $this->addSql('CREATE TABLE ticket_assignments (id BLOB NOT NULL, note CLOB DEFAULT NULL, created_at DATETIME NOT NULL, ticket_id BLOB NOT NULL, department_id BLOB DEFAULT NULL, assigned_to_id BLOB DEFAULT NULL, assigned_by_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_5C1A56A2700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5C1A56A2AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5C1A56A2F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5C1A56A26E6F1246 FOREIGN KEY (assigned_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_ticket_assignments_ticket ON ticket_assignments (ticket_id)');
            $this->addSql('CREATE INDEX IDX_5C1A56A2AE80F5DF ON ticket_assignments (department_id)');
            $this->addSql('CREATE INDEX IDX_5C1A56A2F4BD7827 ON ticket_assignments (assigned_to_id)');
            $this->addSql('CREATE INDEX IDX_5C1A56A26E6F1246 ON ticket_assignments (assigned_by_id)');
            $this->addSql('CREATE TABLE ticket_attachments (id BLOB NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INTEGER NOT NULL, created_at DATETIME NOT NULL, ticket_id BLOB NOT NULL, message_id BLOB DEFAULT NULL, uploaded_by_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_2B54FCA9700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B54FCA9537A1329 FOREIGN KEY (message_id) REFERENCES ticket_messages (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B54FCA9A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_ticket_attachments_ticket ON ticket_attachments (ticket_id)');
            $this->addSql('CREATE INDEX IDX_2B54FCA9537A1329 ON ticket_attachments (message_id)');
            $this->addSql('CREATE INDEX IDX_2B54FCA9A2B28FE8 ON ticket_attachments (uploaded_by_id)');
            $this->addSql('CREATE TABLE ticket_status_history (id BLOB NOT NULL, from_status VARCHAR(32) DEFAULT NULL, to_status VARCHAR(32) NOT NULL, reason CLOB DEFAULT NULL, created_at DATETIME NOT NULL, ticket_id BLOB NOT NULL, changed_by_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_D6921C0D700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D6921C0D828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_ticket_status_history_ticket ON ticket_status_history (ticket_id)');
            $this->addSql('CREATE INDEX IDX_D6921C0D828AD0A0 ON ticket_status_history (changed_by_id)');
            $this->addSql('CREATE TABLE notifications (id BLOB NOT NULL, channel VARCHAR(32) NOT NULL, subject VARCHAR(180) NOT NULL, body CLOB NOT NULL, payload CLOB NOT NULL, read_at DATETIME DEFAULT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, recipient_id BLOB NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_6000B0D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_notifications_recipient_read ON notifications (recipient_id, read_at)');
            $this->addSql('CREATE INDEX IDX_6000B0D3E92F8F78 ON notifications (recipient_id)');
            $this->addSql('CREATE TABLE audit_logs (id BLOB NOT NULL, "action" VARCHAR(120) NOT NULL, entity_type VARCHAR(120) NOT NULL, entity_id VARCHAR(80) DEFAULT NULL, ip_address VARCHAR(80) DEFAULT NULL, context CLOB NOT NULL, created_at DATETIME NOT NULL, actor_id BLOB DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_D62F285810DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id)');
            $this->addSql('CREATE INDEX idx_audit_logs_created_at ON audit_logs (created_at)');
            $this->addSql('CREATE INDEX IDX_D62F285810DAF24A ON audit_logs (actor_id)');
            $this->addSql('CREATE TABLE satisfaction_ratings (id BLOB NOT NULL, score INTEGER NOT NULL, comment CLOB DEFAULT NULL, created_at DATETIME NOT NULL, ticket_id BLOB NOT NULL, member_id BLOB NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_50866793700047D2 FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_508667937597D3FE FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_50866793700047D2 ON satisfaction_ratings (ticket_id)');
            $this->addSql('CREATE INDEX idx_satisfaction_ticket ON satisfaction_ratings (ticket_id)');
            $this->addSql('CREATE INDEX IDX_508667937597D3FE ON satisfaction_ratings (member_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE satisfaction_ratings');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE ticket_status_history');
        $this->addSql('DROP TABLE ticket_attachments');
        $this->addSql('DROP TABLE ticket_assignments');
        $this->addSql('DROP TABLE ticket_messages');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE sla_rules');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE members');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE departments');
    }
}
