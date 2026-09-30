#!/bin/bash
set -e

# 1. Create reportflow_test database if not exists
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    SELECT 'CREATE DATABASE reportflow_test'
    WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'reportflow_test')\gexec

    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'reportflow_tester') THEN
            CREATE ROLE reportflow_tester WITH LOGIN PASSWORD '$POSTGRES_PASSWORD';
        ELSE
            ALTER ROLE reportflow_tester WITH PASSWORD '$POSTGRES_PASSWORD';
        END IF;
    END
    \$\$;

    -- Lock down dev database: only $POSTGRES_USER can connect, reportflow_tester is blocked
    REVOKE CONNECT ON DATABASE "$POSTGRES_DB" FROM PUBLIC;
    GRANT CONNECT ON DATABASE "$POSTGRES_DB" TO "$POSTGRES_USER";
    REVOKE ALL PRIVILEGES ON DATABASE "$POSTGRES_DB" FROM reportflow_tester;

    -- Grant full privileges on test database to reportflow_tester
    GRANT ALL PRIVILEGES ON DATABASE reportflow_test TO reportflow_tester;
    ALTER DATABASE reportflow_test OWNER TO reportflow_tester;
EOSQL

# 2. Configure schema & extensions inside reportflow_test
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "reportflow_test" <<-EOSQL
    CREATE EXTENSION IF NOT EXISTS pg_trgm;
    GRANT ALL ON SCHEMA public TO reportflow_tester;
    GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO reportflow_tester;
    GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO reportflow_tester;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO reportflow_tester;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO reportflow_tester;
EOSQL
