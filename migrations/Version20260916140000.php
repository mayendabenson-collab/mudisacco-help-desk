<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add staff permissions field to users table';
    }

    public function up(Schema $schema): void
    {
        $isPg = $this->platform instanceof PostgreSQLPlatform;

        if ($isPg) {
            $this->addSql("ALTER TABLE users ADD staff_permissions JSON NOT NULL DEFAULT '[]'");
        } else {
            $this->addSql("ALTER TABLE users ADD COLUMN staff_permissions JSON NOT NULL DEFAULT '[]'");
        }
    }

    public function down(Schema $schema): void
    {
        $isPg = $this->platform instanceof PostgreSQLPlatform;

        if ($isPg) {
            $this->addSql('ALTER TABLE users DROP staff_permissions');
        } else {
            $this->addSql('ALTER TABLE users DROP COLUMN staff_permissions');
        }
    }
}
