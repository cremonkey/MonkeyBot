-- SPEC-20: hybrid RAG retrieval — semantic (embeddings) + lexical (FULLTEXT)
--
-- Arabic customer queries score 0 against an all-English knowledge base because
-- MATCH..AGAINST is lexical. Store an embedding per chunk so cross-lingual
-- semantic search can find them.
--
-- Additive and nullable: chunks without an embedding stay reachable via
-- FULLTEXT exactly as before, so this cannot break the running code path.
--
-- Vector is packed float32 little-endian (pack('g*', ...)), 1536 dims = 6144 bytes.

ALTER TABLE `ai_knowledge_chunks`
    ADD COLUMN `embedding` BLOB NULL DEFAULT NULL AFTER `chunk_text`,
    ADD COLUMN `embedding_model` VARCHAR(64) NULL DEFAULT NULL AFTER `embedding`;
