CREATE DATABASE IF NOT EXISTS treino_app
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE treino_app;

CREATE TABLE IF NOT EXISTS clientes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clientes_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS treinos_clientes (
  cliente_id BIGINT UNSIGNED NOT NULL,
  dados_json LONGTEXT NOT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (cliente_id),
  CONSTRAINT fk_treinos_clientes_cliente
    FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notas_fiscais (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id BIGINT UNSIGNED NOT NULL,
  referencia VARCHAR(120) NOT NULL,
  status VARCHAR(80) NOT NULL DEFAULT 'processando_autorizacao',
  numero VARCHAR(30) NULL,
  chave VARCHAR(60) NULL,
  caminho_xml VARCHAR(255) NULL,
  caminho_danfe VARCHAR(255) NULL,
  mensagem VARCHAR(500) NULL,
  payload_json LONGTEXT NOT NULL,
  resposta_json LONGTEXT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notas_referencia (referencia),
  KEY idx_notas_cliente (cliente_id),
  CONSTRAINT fk_notas_cliente
    FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB;
