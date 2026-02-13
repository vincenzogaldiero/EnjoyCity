
-- 1. Creazione Utente e Database (Da eseguire come superuser, es: postgres)
-- NOTA: Se l'utente esiste già, la riga seguente darà errore, ignorala pure.
DO
$do$
BEGIN
   IF NOT EXISTS (
      SELECT FROM pg_catalog.pg_roles
      WHERE  rolname = 'www') THEN
      CREATE ROLE "www" WITH LOGIN PASSWORD 'www';
   END IF;
END
$do$;

-- In un ambiente reale creeresti il DB qui, ma spesso su hosting/locali il DB lo crei a mano.
-- Nome DB: gruppo22
-- Owner: www

-- ------------------------------------------------------------------
-- CONNETTITI AL DATABASE 'gruppo22' PRIMA DI ESEGUIRE IL RESTO!
-- ------------------------------------------------------------------

-- 2. Eliminazione tabelle se esistono (pulizia per reinstallazione)
DROP TABLE IF EXISTS recensioni CASCADE;
DROP TABLE IF EXISTS prenotazioni CASCADE;
DROP TABLE IF EXISTS preferenze_utente CASCADE;
DROP TABLE IF EXISTS eventi CASCADE;
DROP TABLE IF EXISTS categorie CASCADE;
DROP TABLE IF EXISTS utenti CASCADE;

-- 3. Creazione Tabella Utenti
CREATE TABLE utenti (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL, -- Qui salveremo l'hash
    ruolo VARCHAR(20) NOT NULL CHECK (ruolo IN ('admin', 'user')),
    bloccato BOOLEAN DEFAULT FALSE,
    data_registrazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Creazione Tabella Categorie
CREATE TABLE categorie (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE
);

-- 5. Creazione Tabella Eventi
CREATE TABLE eventi (
    id SERIAL PRIMARY KEY,
    titolo VARCHAR(100) NOT NULL,
    descrizione_breve VARCHAR(255) NOT NULL,
    descrizione_lunga TEXT NOT NULL,
    immagine VARCHAR(255), -- Percorso immagine
    data_evento TIMESTAMP NOT NULL,
    luogo VARCHAR(100) NOT NULL,
    latitudine DECIMAL(9,6), -- Per geolocalizzazione
    longitudine DECIMAL(9,6),
    prezzo DECIMAL(10,2) DEFAULT 0.00,
    posti_totali INT NOT NULL,
    posti_prenotati INT DEFAULT 0,
    prenotazione_obbligatoria BOOLEAN DEFAULT FALSE,
    stato VARCHAR(20) DEFAULT 'in_attesa' CHECK (stato IN ('approvato', 'in_attesa', 'rifiutato')),
    organizzatore_id INT REFERENCES utenti(id) ON DELETE SET NULL
);

-- 6. Creazione Tabella Prenotazioni (Countdown e MyEvents)
CREATE TABLE prenotazioni (
    id SERIAL PRIMARY KEY,
    utente_id INT REFERENCES utenti(id) ON DELETE CASCADE,
    evento_id INT REFERENCES eventi(id) ON DELETE CASCADE,
    quantita INT DEFAULT 1,
    data_prenotazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Creazione Tabella Recensioni (Dicono di noi - Sul sito)
CREATE TABLE recensioni (
    id SERIAL PRIMARY KEY,
    utente_id INT REFERENCES utenti(id) ON DELETE CASCADE,
    testo TEXT NOT NULL,
    voto INT CHECK (voto >= 1 AND voto <= 5),
    stato VARCHAR(20) DEFAULT 'in_attesa' CHECK (stato IN ('approvato', 'in_attesa', 'rifiutato')),
    data_recensione TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Creazione Tabella Preferenze (Drag & Drop ordinamento)
CREATE TABLE preferenze_utente (
    utente_id INT REFERENCES utenti(id) ON DELETE CASCADE,
    categoria_id INT REFERENCES categorie(id) ON DELETE CASCADE,
    ordine INT NOT NULL, -- 1, 2, 3...
    PRIMARY KEY (utente_id, categoria_id)
);

-- Collegamento Eventi-Categorie (Molti a Molti semplificato: aggiungo colonna a eventi per semplicità o tabella ponte)
-- Per semplicità nel progetto scolastico, aggiungo la FK direttamente in eventi
ALTER TABLE eventi ADD COLUMN categoria_id INT REFERENCES categorie(id);

-- Assegnazione permessi all'utente 'www'
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO "www";
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO "www";

-- ------------------------------------------------------------------
-- POPOLAMENTO DATI (INSERT)
-- ------------------------------------------------------------------

-- Categorie
INSERT INTO categorie (nome) VALUES ('Sagre'), ('Musica'), ('Teatro'), ('Sport'), ('Cultura');

-- Utenti (Password fittizia 'pass123' - in PHP useremo password_hash)
-- Inseriamo un Admin e due User
INSERT INTO utenti (nome, cognome, email, password, ruolo) VALUES 
('Mario', 'Rossi', 'admin@avellino.it', '$2y$10$abcdefghilmnopqrstuvz', 'admin'), -- hash finto per esempio
('Luca', 'Verdi', 'luca@email.it', '$2y$10$abcdefghilmnopqrstuvz', 'user'),
('Anna', 'Bianchi', 'anna@email.it', '$2y$10$abcdefghilmnopqrstuvz', 'user');

-- Eventi (Avellino e Provincia)
-- Coordinate Avellino Centro: 40.914, 14.797
INSERT INTO eventi (titolo, descrizione_breve, descrizione_lunga, data_evento, luogo, latitudine, longitudine, prezzo, posti_totali, stato, categoria_id, prenotazione_obbligatoria) VALUES
('Sagra della Castagna', 'La famosa sagra di Montella IGP', 'Vieni a gustare le migliori castagne in tutte le salse.', '2026-11-05 18:00:00', 'Montella', 40.841, 15.016, 0.00, 1000, 'approvato', 1, false),
('Concerto al Gesualdo', 'Orchestra sinfonica', 'Una serata magica al Teatro Carlo Gesualdo di Avellino.', '2026-06-20 21:00:00', 'Teatro Gesualdo', 40.916, 14.792, 25.00, 500, 'approvato', 3, true),
('Avellino Summer Fest', 'Musica in piazza', 'Concerto gratuito lungo il Corso Vittorio Emanuele.', '2026-08-15 20:00:00', 'Corso Vittorio Emanuele', 40.914, 14.797, 0.00, 2000, 'approvato', 2, false),
('Torneo Calcetto', 'Torneo amatoriale', 'Sfida tra quartieri.', '2026-07-10 16:00:00', 'Campo Coni', 40.908, 14.785, 5.00, 100, 'in_attesa', 4, true);

-- Prenotazioni (Per testare la dashboard user)
INSERT INTO prenotazioni (utente_id, evento_id, quantita) VALUES
(2, 2, 2); -- Luca ha prenotato 2 biglietti per il teatro

-- Recensioni (Dicono di noi)
INSERT INTO recensioni (utente_id, testo, voto, stato) VALUES
(2, 'Sito fantastico per scoprire l Irpinia!', 5, 'approvato'),
(3, 'Vorrei più eventi sportivi, ma bella grafica.', 4, 'in_attesa');

-- Preferenze (Per Luca)
INSERT INTO preferenze_utente (utente_id, categoria_id, ordine) VALUES
(2, 3, 1), -- Teatro preferito
(2, 1, 2); -- Sagre seconda scelta