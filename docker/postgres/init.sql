-- docker/postgres/init.sql
CREATE EXTENSION IF NOT EXISTS vector;

-- Test de l'extension (optionnel)
SELECT vector_dims('[1,2,3]'::vector);