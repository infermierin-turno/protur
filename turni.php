<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Funzione di supporto personalizzata per le chiamate a Supabase con forzatura dello schema public
function supabase_turni_request($endpoint, $method = 'GET', $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $ch = curl_init($url);
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept-Profile: public',
        'Content-Profile: public'
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return $method === 'GET' ? [] : false;
}

// Funzione per comunicare con il microservizio Python su Render
function call_python_engine_genera($payload = []) {
    $url = 'https://turno-med-engine.onrender.com/genera-turni';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Errore cURL: " . $error];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return is_array($decoded) ? $decoded : ['success' => true, 'response' => $response];
    }

    return [
        'success' => false, 
        'error' => "HTTP Code: $httpCode", 
        'response' => $response,
        'raw_output' => $response
    ];
}

// 1. Estrazione sicura dei dati dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

// Ruoli e permessi
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? $_SESSION['utente']['is_super_admin'] ?? false;
if (!$is_super_admin && in_array($ruolo_lower, ['super_admin', 'admin', 'superadmin'])) {
    $is_super_admin = true;
}

$is_capo_personale = in_array($ruolo_lower, ['capo_personale', 'capopersonale']);
$is_coordinatore = ($ruolo_lower === 'coordinatore');

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

// Se in sessione manca l'organizzazione_id o il reparto_id, li recuperiamo direttamente dal DB per l'utente loggato
if (empty($org_id_utente) || empty($reparto_id_utente)) {
    $resUCorr = supabase_turni_request("staging_utenti?id=eq.$utente_id&select=organizzazione_id,reparto_id");
    if (!empty($resUCorr) && is_array($resUCorr)) {
        if (empty($org_id_utente)) {
            $org_id_utente = $resUCorr[0]['organizzazione_id'] ?? null;
        }
        if (empty($reparto_id_utente)) {
            $reparto_id_utente = $resUCorr[0]['reparto_id'] ?? null;
        }
    }
}

// 2. Recupero di tutti i reparti (filtrati per organizzazione se è un capo personale)
$reparti_disponibili = [];
$urlReparti = "reparti?select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc";
if ($is_capo_personale && !empty($org_id_utente)) {
    $urlReparti = "reparti?organizzazione_id=eq.$org_id_utente&select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc";
}
$resReparti = supabase_turni_request($urlReparti);
if (is_array($resReparti) && !isset($resReparti['error'])) {
    $reparti_disponibili = $resReparti;
}

// 3. Gestione Reparto Selezionato
$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && $_GET['reparto_id'] !== '') {
        $reparto_selezionato = $_GET['reparto_id'];
    }
} else {
    $reparto_selezionato = $reparto_id_utente;
}

// 4. Determinazione del nome del reparto e della struttura da mostrare a schermo
$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti della Struttura" : "Reparto non assegnato";
$nome_struttura_corrente = "Azienda Sanitaria / Struttura";

if (!empty($org_id_utente)) {
    $res_org_filtered = supabase_turni_request("organizzazioni?id=eq.$org_id_utente&select=nome_struttura");
    if (!empty($res_org_filtered) && is_array($res_org_filtered)) {
        $nome_struttura_corrente = $res_org_filtered[0]['nome_struttura'] ?? $res_org_filtered[0]['NOME_STRUTTURA'] ?? 'Azienda Sanitaria';
    }
}

if ($reparto_selezionato !== '') {
    foreach ($reparti_disponibili as $rep) {
        if ((string)$rep['id'] === (string)$reparto_selezionato) {
            $nome_reparto_selezionato = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
            break;
        }
    }
}

$messaggio = '';
$tipo_alert = '';

$queryTipologie = "tipologie_turno?select=*";
$tipologieTurni = supabase_turni_request($queryTipologie);

if (!is_array($tipologieTurni) || empty($tipologieTurni)) {
    $tipologieTurni = [
        ['id' => '1', 'nome_turno' => 'Mattina', 'codice_breve' => 'M', 'colore' => '#0d6efd', 'ora_inizio' => '07:00:00', 'ora_fine' => '14:00:00'],
        ['id' => '2', 'nome_turno' => 'Pomeriggio', 'codice_breve' => 'P', 'colore' => '#ffc107', 'ora_inizio' => '14:00:00', 'ora_fine' => '21:00:00'],
        ['id' => '3', 'nome_turno' => 'Notte', 'codice_breve' => 'N', 'colore' => '#6610f2', 'ora_inizio' => '21:00:00', 'ora_fine' => '07:00:00'],
        ['id' => '4', 'nome_turno' => 'Smonto', 'codice_breve' => 'S', 'colore' => '#fd7e14', 'ora_inizio' => '07:00:00', 'ora_fine' => '14:00:00'],
        ['id' => '5', 'nome_turno' => 'Riposo', 'codice_breve' => 'R', 'colore' => '#198754', 'ora_inizio' => '00:00:00', 'ora_fine' => '23:59:59'],
        ['id' => '6', 'nome_turno' => 'Ferie', 'codice_breve' => 'F', 'colore' => '#20c997', 'ora_inizio' => '00:00:00', 'ora_fine' => '23:59:59']
    ];
}

$mappaColoriTurni = [];
$mappaOrariTurni = [];
foreach ($tipologieTurni as $t) {
    $codice = strtoupper(trim($t['codice_breve'] ?? ''));
    $coloreHex = $t['colore'] ?? $t['colore_hex'] ?? $t['color'] ?? '#6c757d';
    if (!empty($codice)) {
        $mappaColoriTurni[$codice] = $coloreHex;
        $mappaOrariTurni[$codice] = [
            'inizio' => $t['ora_inizio'] ?? '08:00:00',
            'fine' => $t['ora_fine'] ?? '14:00:00'
        ];
    }
}
if (!isset($mappaColoriTurni['F'])) {
    $mappaColoriTurni['F'] = '#20c997';
}

function verificaVincoliTurno($utente_id, $data_str, $nuovo_codice, $turno_escludere_id = null) {
    $inizioGiorno = $data_str . ' 00:00:00';
    $fineGiorno = $data_str . ' 23:59:59';
    $urlGiorno = "pianificazione?utente_id=eq.$utente_id&data_inizio=gte." . urlencode($inizioGiorno) . "&data_inizio=lte." . urlencode($fineGiorno) . "&select=*";
    
    $turniGiorno = supabase_turni_request($urlGiorno);
    if (!is_array($turniGiorno)) {
        $turniGiorno = [];
    }

    if (!empty($turno_escludere_id)) {
        $turniGiorno = array_filter($turniGiorno, function($t) use ($turno_escludere_id) {
            return (string)$t['id'] !== (string)$turno_escludere_id;
        });
    }

    $haSmontoOggi = false;
    foreach ($turniGiorno as $tg) {
        $tipoEsistente = strtoupper(trim($tg['tipo_evento']));
        if ($tipoEsistente === 'S' || $tipoEsistente === 'SMONTO') {
            $haSmontoOggi = true;
            break;
        }
    }

    $nuovoCodiceUpper = strtoupper(trim($nuovo_codice));
    if ($haSmontoOggi) {
        if ($nuovoCodiceUpper === 'M' || $nuovoCodiceUpper === 'MATTINA' || $nuovoCodiceUpper === 'P' || $nuovoCodiceUpper === 'POMERIGGIO') {
            return "ATTENZIONE: Impossibile assegnare turno richiesto, stai violando la regola che prevede il riposo minimo di 11 ore dopo la notte!.";
        }
    }

    if (count($turniGiorno) >= 2) {
        return "Errore: L'operatore ha già raggiunto il limite massimo di 2 turni in questa data.";
    }

    return true;
}

// COSTRUZIONE MAPPA UTENTI CON ISOLAMENTO MULTI-TENANT RIGOROSO
$mappaUtenti = [];

if ($is_super_admin) {
    // Super admin vede tutti
    $urlUtenti = "staging_utenti?select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    if ($reparto_selezionato !== '') {
        $urlUtenti = "staging_utenti?reparto_id=eq." . urlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    }
} elseif ($is_capo_personale) {
    // Capo Personale: FILTRO TASSATIVO SULL'ORGANIZZAZIONE_ID (Struttura A)
    if (!empty($org_id_utente)) {
        if ($reparto_selezionato !== '') {
            $urlUtenti = "staging_utenti?organizzazione_id=eq.$org_id_utente&reparto_id=eq." . urlencode($reparto_selezionato) . "&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
        } else {
            $urlUtenti = "staging_utenti?organizzazione_id=eq.$org_id_utente&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
        }
    } else {
        $urlUtenti = ""; // Nessuna organizzazione -> nessun utente
    }
} else {
    // Coordinatore o altro ruolo: filtra per reparto
    if (!empty($reparto_id_utente)) {
        $urlUtenti = "staging_utenti?reparto_id=eq.$reparto_id_utente&select=id,nome,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    } else {
        $urlUtenti = "";
    }
}

if (!empty($urlUtenti)) {
    $resCollab = supabase_turni_request($urlUtenti);
    if (is_array($resCollab) && !isset($resCollab['error'])) {
        foreach ($resCollab as $c) {
            $rC_low = strtolower(str_replace([' ', '-'], '_', $c['ruolo'] ?? ''));
            if (in_array($rC_low, ['super_admin', 'admin', 'superadmin', 'capo_personale', 'capopersonale'])) {
                continue;
            }
            $mappaUtenti[$c['id']] = $c;
        }
    }
}

$listaCollaboratori = array_values($mappaUtenti);
$idsUtentiReparto = array_keys($mappaUtenti);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'aggiungi_turno_cella') {
        $utente_cell = trim($_POST['utente_id'] ?? '');
        $data_cell = trim($_POST['data'] ?? '');
        $nuovo_valore = trim($_POST['valore'] ?? '');

        if (!empty($utente_cell) && isset($mappaUtenti[$utente_cell]) && !empty($data_cell) && !empty($nuovo_valore)) {
            $checkVincoli = verificaVincoliTurno($utente_cell, $data_cell, $nuovo_valore);
            if ($checkVincoli === true) {
                $datiPost = [
                    'organizzazione_id' => !empty($org_id_utente) ? $org_id_utente : null,
                    'reparto_id' => !empty($mappaUtenti[$utente_cell]['reparto_id']) ? $mappaUtenti[$utente_cell]['reparto_id'] : null,
                    'utente_id' => $utente_cell,
                    'data_inizio' => $data_cell . ' 00:00:00+00',
                    'data_fine' => $data_cell . ' 23:59:59+00',
                    'tipo_evento' => $nuovo_valore,
                    'stato' => 'Approvato',
                    'note' => 'Aggiunta turno da modale tabellone'
                ];
                $res = supabase_turni_request("pianificazione", 'POST', $datiPost);
                if ($res !== false) {
                    $messaggio = "Turno aggiunto con successo!";
                    $tipo_alert = "success";
                } else {
                    $messaggio = "Errore durante l'inserimento del turno nel database.";
                    $tipo_alert = "danger";
                }
            } else {
                $messaggio = $checkVincoli;
                $tipo_alert = "danger";
            }
        }
    }

    if ($azione === 'modifica_turno_cella') {
        $turno_id = trim($_POST['turno_id'] ?? '');
        $utente_cell = trim($_POST['utente_id'] ?? '');
        $data_cell = trim($_POST['data'] ?? '');
        $nuovo_valore = trim($_POST['valore'] ?? '');
        $nuovo_stato = trim($_POST['stato'] ?? 'Approvato');

        if (!empty($turno_id) && !empty($utente_cell) && isset($mappaUtenti[$utente_cell]) && !empty($data_cell) && !empty($nuovo_valore)) {
            $checkVincoli = verificaVincoliTurno($utente_cell, $data_cell, $nuovo_valore, $turno_id);
            if ($checkVincoli === true) {
                $datiPatch = [
                    'tipo_evento' => $nuovo_valore,
                    'stato' => $nuovo_stato,
                    'note' => 'Modificato da modale tabellone'
                ];
                $res = supabase_turni_request("pianificazione?id=eq.$turno_id", 'PATCH', $datiPatch);
                if ($res !== false) {
                    $messaggio = "Turno aggiornato con successo!";
                    $tipo_alert = "success";
                } else {
                    $messaggio = "Errore durante la modifica del turno.";
                    $tipo_alert = "danger";
                }
            } else {
                $messaggio = $checkVincoli;
                $tipo_alert = "danger";
            }
        }
    }

    if ($azione === 'cancella_turno_cella') {
        $turno_id = trim($_POST['turno_id'] ?? '');
        if (!empty($turno_id)) {
            $res = supabase_turni_request("pianificazione?id=eq.$turno_id", 'DELETE');
            if ($res !== false) {
                $messaggio = "Turno cancellato con successo!";
                $tipo_alert = "success";
            } else {
                $messaggio = "Errore durante la cancellazione del turno.";
                $tipo_alert = "danger";
            }
        }
    }

    if ($azione === 'assegna_turno') {
        $collaboratore_selezionato = trim($_POST['collaboratore_id'] ?? '');
        $tipologia_turno_id = trim($_POST['tipologia_turno_id'] ?? '');
        $data_inizio = trim($_POST['data_inizio'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if (!empty($collaboratore_selezionato) && isset($mappaUtenti[$collaboratore_selezionato]) && !empty($tipologia_turno_id) && !empty($data_inizio)) {
            $checkVincoli = verificaVincoliTurno($collaboratore_selezionato, $data_inizio, $tipologia_turno_id);
            if ($checkVincoli === true) {
                $datiPianificazione = [
                    'organizzazione_id' => !empty($org_id_utente) ? $org_id_utente : null,
                    'reparto_id' => !empty($mappaUtenti[$collaboratore_selezionato]['reparto_id']) ? $mappaUtenti[$collaboratore_selezionato]['reparto_id'] : null,
                    'utente_id' => $collaboratore_selezionato,
                    'data_inizio' => $data_inizio . ' 00:00:00+00',
                    'data_fine' => $data_inizio . ' 23:59:59+00',
                    'tipo_evento' => $tipologia_turno_id,
                    'stato' => 'Approvato',
                    'note' => !empty($note) ? $note : "Assegnazione manuale"
                ];

                $resp = supabase_turni_request("pianificazione", 'POST', $datiPianificazione);
                if ($resp !== false) {
                    $messaggio = "Turno assegnato con successo!";
                    $tipo_alert = "success";
                } else {
                    $messaggio = "Errore durante l'assegnazione del turno.";
                    $tipo_alert = "danger";
                }
            } else {
                $messaggio = $checkVincoli;
                $tipo_alert = "danger";
            }
        }
    }

    if ($azione === 'genera_turni_ai') {
        $data_inizio_gen = trim($_POST['data_inizio_gen'] ?? '');
        $data_fine_gen = trim($_POST['data_fine_gen'] ?? '');
        $collaboratore_gen = trim($_POST['collaboratore_gen'] ?? ''); 
        $turno_partenza = trim($_POST['turno_partenza'] ?? ''); 
        $riposo_domenicale = isset($_POST['riposo_domenicale']) ? true : false; 

        if (!empty($data_inizio_gen) && !empty($data_fine_gen)) {
            $clean_org_id = (!empty($org_id_utente)) ? $org_id_utente : null;
            $clean_rep_id = (!empty($reparto_selezionato)) ? $reparto_selezionato : null;
            $clean_user_id = (!empty($collaboratore_gen) && isset($mappaUtenti[$collaboratore_gen])) ? $collaboratore_gen : null;
            $clean_turno_partenza = (!empty($turno_partenza)) ? $turno_partenza : null;

            $payloadAI = [
                'organizzazione_id' => $clean_org_id,
                'reparto_id' => $clean_rep_id,
                'data_inizio' => $data_inizio_gen,
                'data_fine' => $data_fine_gen,
                'utente_id' => $clean_user_id,
                'turno_partenza' => $clean_turno_partenza,
                'rispetta_ferie' => true,
                'riposo_domenicale' => $riposo_domenicale 
            ];

            $risultatoAI = call_python_engine_genera($payloadAI);
            if (isset($risultatoAI['success']) && $risultatoAI['success'] === true) {
                $messaggio = "Generazione turni tramite AI completata con successo!";
                $tipo_alert = "success";
            } else {
                $detErr = $risultatoAI['response'] ?? $risultatoAI['error'] ?? 'Sconosciuto';
                $messaggio = "Errore dal motore AI: " . ($risultatoAI['error'] ?? 'Riconosciuto') . " | Dettaglio: " . htmlspecialchars($detErr);
                $tipo_alert = "danger";
            }
        }
    }
}

$mese_selezionato = isset($_GET['mese']) ? intval($_GET['mese']) : intval(date('m'));
$anno_selezionato = isset($_GET['anno']) ? intval($_GET['anno']) : intval(date('Y'));
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese_selezionato, $anno_selezionato);
$primo_giorno_tab = sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato);
$ultimo_giorno_tab = sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);

$queryTurniMese = "pianificazione?select=*&data_inizio=gte." . $primo_giorno_tab . "&data_inizio=lte." . $ultimo_giorno_tab;
if (!empty($idsUtentiReparto)) {
    $queryTurniMese .= "&utente_id=in.(" . implode(',', $idsUtentiReparto) . ")";
} else {
    $queryTurniMese .= "&utente_id=eq.00000000-0000-0000-0000-000000000000";
}
$turniMeseData = supabase_turni_request($queryTurniMese);

$queryAssenzeMese = "assenze?select=*&data_inizio=lte." . $ultimo_giorno_tab . "&data_fine=gte." . $primo_giorno_tab;
if (!empty($idsUtentiReparto)) {
    $queryAssenzeMese .= "&utente_id=in.(" . implode(',', $idsUtentiReparto) . ")";
} else {
    $queryAssenzeMese .= "&utente_id=eq.00000000-0000-0000-0000-000000000000";
}
$assenzeMeseData = supabase_turni_request($queryAssenzeMese);

$mappaTurniGriglia = [];

if (is_array($turniMeseData)) {
    foreach ($turniMeseData as $tm) {
        $uId = $tm['utente_id'];
        $giornoNum = intval(date('d', strtotime($tm['data_inizio'])));
        $mappaTurniGriglia[$uId][$giornoNum][] = [
            'id' => $tm['id'],
            'tipo_evento' => $tm['tipo_evento'],
            'stato' => $tm['stato'] ?? 'Approvato'
        ];
    }
}

if (is_array($assenzeMeseData)) {
    foreach ($assenzeMeseData as $as) {
        $uId = $as['utente_id'];
        $tipoEvento = $as['tipo_evento'] ?? $as['tipo_assenza'] ?? 'F';
        $statoAssenza = $as['stato'] ?? 'In attesa';
        
        $dInizio = max($primo_giorno_tab, substr($as['data_inizio'], 0, 10));
        $dFine = min($ultimo_giorno_tab, substr($as['data_fine'] ?? $as['data_inizio'], 0, 10));
        
        $currentTimestamp = strtotime($dInizio);
        $endTimestamp = strtotime($dFine);
        
        while ($currentTimestamp <= $endTimestamp) {
            $giornoNum = intval(date('d', $currentTimestamp));
            
            $giaPresente = false;
            if (isset($mappaTurniGriglia[$uId][$giornoNum])) {
                foreach ($mappaTurniGriglia[$uId][$giornoNum] as $evEsistente) {
                    if (strcasecmp($evEsistente['tipo_evento'], $tipoEvento) === 0) {
                        $giaPresente = true;
                        break;
                    }
                }
            }
            
            if (!$giaPresente) {
                $mappaTurniGriglia[$uId][$giornoNum][] = [
                    'id' => $as['id'] ?? 'ass_' . rand(1000,9999),
                    'tipo_evento' => $tipoEvento,
                    'stato' => $statoAssenza
                ];
            }
            
            $currentTimestamp = strtotime('+1 day', $currentTimestamp);
        }
    }
}

$mesiNomi = [1=>'Gennaio', 2=>'Febbraio', 3=>'Marzo', 4=>'Aprile', 5=>'Maggio', 6=>'Giugno', 7=>'Luglio', 8=>'Agosto', 9=>'Settembre', 10=>'Ottobre', 11=>'Novembre', 12=>'Dicembre'];
$nomeMeseCorrente = $mesiNomi[$mese_selezionato] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestione Turni - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8fafc; padding-bottom: 70px; color: #334155; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); background: #ffffff; }
        .table-turni th, .table-turni td { text-align: center; vertical-align: middle; font-size: 0.75rem; padding: 6px 4px; }
        .cella-interattiva { cursor: pointer; transition: background-color 0.2s; white-space: nowrap; }
        .cella-interattiva:hover { background-color: #e2e6ea !important; font-weight: bold; }
        .col-operatore-sticky { position: sticky; left: 0; background-color: #ffffff; z-index: 2; text-align: left !important; min-width: 170px; font-weight: 600; padding-left: 8px !important; }
        .badge-turno {
            display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 20px; font-size: 0.65rem; font-weight: 700; color: #fff;
            border-radius: 4px; margin: 0 auto; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .badge-in-attesa {
            border: 2px dashed #ffc107 !important;
            opacity: 0.85;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Gestione Turni Ospedalieri</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link active" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <!-- Header con Titolo e Box Reparto / Struttura in alto -->
        <div class="card shadow-sm mb-4 p-3">
            <div class="row align-items-center g-3">
                <div class="col-md-6">
                    <h2 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-check text-primary"></i> Assegnazione e Gestione Turni
                    </h2>
                    <span class="small text-muted">
                        <i class="bi bi-building"></i> Struttura: <strong class="text-dark"><?php echo htmlspecialchars($nome_struttura_corrente); ?></strong> | 
                        Reparto: <strong class="text-dark"><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong>
                    </span>
                </div>
                <div class="col-md-6 text-md-end">
                    <form method="GET" action="turni.php" class="d-inline-flex gap-2 align-items-center">
                        <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                            <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Tutti i Reparti della Struttura</option>
                                <?php foreach ($reparti_disponibili as $rep) { 
                                    $labelRep = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
                                ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato !== '' && (string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelRep); ?></option>
                                <?php } ?>
                            </select>
                        <?php } ?>
                        <select name="mese" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($mesiNomi as $numM => $strM) { ?>
                                <option value="<?php echo $numM; ?>" <?php echo ($numM === $mese_selezionato) ? 'selected' : ''; ?>><?php echo $strM; ?></option>
                            <?php } ?>
                        </select>
                        <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($a = 2024; $a <= 2030; $a++) { ?>
                                <option value="<?php echo $a; ?>" <?php echo ($a === $anno_selezionato) ? 'selected' : ''; ?>><?php echo $a; ?></option>
                            <?php } ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small shadow-sm" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <div class="row mb-4">
            <div class="col-lg-6 mb-3 mb-lg-0">
                <div class="card h-100 bg-white p-3">
                    <div class="card-header bg-white pb-2 px-0 border-bottom">
                        <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-person-plus-fill"></i> Assegnazione Singola Manuale</h5>
                    </div>
                    <div class="card-body px-0">
                        <form method="POST" action="turni.php?mese=<?php echo $mese_selezionato; ?>&anno=<?php echo $anno_selezionato; ?>&reparto_id=<?php echo $reparto_selezionato; ?>">
                            <input type="hidden" name="azione" value="assegna_turno">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Collaboratore</label>
                                <select class="form-select form-select-sm" name="collaboratore_id" required>
                                    <option value="">-- Seleziona collaboratore --</option>
                                    <?php foreach ($listaCollaboratori as $collab) { ?>
                                        <option value="<?php echo htmlspecialchars($collab['id']); ?>">
                                            <?php echo htmlspecialchars($collab['nome']); ?> (<?php echo htmlspecialchars($collab['ruolo'] ?? $collab['qualifica'] ?? 'N/D'); ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Tipologia Turno</label>
                                <select class="form-select form-select-sm" name="tipologia_turno_id" required>
                                    <option value="">-- Seleziona turno --</option>
                                    <?php foreach ($tipologieTurni as $t) { ?>
                                        <option value="<?php echo htmlspecialchars($t['codice_breve']); ?>">
                                            <?php echo htmlspecialchars($t['nome_turno']); ?> (<?php echo htmlspecialchars($t['codice_breve']); ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Data Inizio</label>
                                <input type="date" class="form-control form-control-sm" name="data_inizio" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Note (Opzionale)</label>
                                <input type="text" class="form-control form-control-sm" name="note" placeholder="Eventuali annotazioni...">
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-dark btn-sm fw-bold">Assegna Turno</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100 bg-white p-3">
                    <div class="card-header bg-white pb-2 px-0 border-bottom">
                        <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-cpu-fill text-primary"></i> Generazione Automatica Turni (AI Ad Personam)</h5>
                    </div>
                    <div class="card-body px-0">
                        <form method="POST" action="turni.php?mese=<?php echo $mese_selezionato; ?>&anno=<?php echo $anno_selezionato; ?>&reparto_id=<?php echo $reparto_selezionato; ?>">
                            <input type="hidden" name="azione" value="genera_turni_ai">
                            
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Collaboratore (Opzionale)</label>
                                <select class="form-select form-select-sm" name="collaboratore_gen">
                                    <option value="">-- Tutti i collaboratori abilitati --</option>
                                    <?php foreach ($listaCollaboratori as $collab) { ?>
                                        <option value="<?php echo htmlspecialchars($collab['id']); ?>">
                                            <?php echo htmlspecialchars($collab['nome']); ?> (<?php echo htmlspecialchars($collab['ruolo'] ?? $collab['qualifica'] ?? 'N/D'); ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                                <div class="form-text" style="font-size: 0.75rem;">Seleziona un operatore per applicare le regole <b>ad personam</b>.</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small fw-bold">Data Inizio Periodo</label>
                                    <input type="date" class="form-control form-control-sm" name="data_inizio_gen" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small fw-bold">Data Fine Periodo</label>
                                    <input type="date" class="form-control form-control-sm" name="data_fine_gen" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold">Turno di Partenza Obbligatorio (Giorno 1)</label>
                                <select class="form-select form-select-sm" name="turno_partenza">
                                    <option value="">-- Nessun vincolo (Scelta automatica AI) --</option>
                                    <?php foreach ($tipologieTurni as $t) { ?>
                                        <option value="<?php echo htmlspecialchars($t['codice_breve']); ?>">
                                            <?php echo htmlspecialchars($t['nome_turno']); ?> (<?php echo htmlspecialchars($t['codice_breve']); ?>)
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="riposo_domenicale" name="riposo_domenicale" value="1" checked>
                                <label class="form-check-label small fw-bold text-dark" for="riposo_domenicale">
                                    Garantisci domeniche di riposo (R) per regimi mattinieri/standard
                                </label>
                                <div class="form-text" style="font-size: 0.70rem;"><i class="bi bi-shield-check text-success"></i> Se attivo, l'algoritmo imposterà automaticamente il riposo nei giorni festivi domenicali.</div>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-primary btn-sm fw-bold"><i class="bi bi-magic"></i> Genera Turni AI</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 bg-white p-3">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <span class="small fw-bold text-muted"><i class="bi bi-info-circle"></i> Legenda Turni:</span>
                <?php foreach ($tipologieTurni as $t): 
                    $cBreve = strtoupper(trim($t['codice_breve'] ?? ''));
                    $nTurno = $t['nome_turno'] ?? $cBreve;
                    $colHex = $t['colore'] ?? '#6c757d';
                ?>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge-turno" style="background-color: <?php echo htmlspecialchars($colHex); ?>;"><?php echo htmlspecialchars($cBreve); ?></span>
                        <span class="small text-secondary"><?php echo htmlspecialchars($nTurno); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex align-items-center gap-1 ms-3 border-start ps-3">
                    <span class="badge-turno badge-in-attesa bg-secondary">F</span>
                    <span class="small text-secondary">Bordo tratteggiato = In attesa di approvazione</span>
                </div>
            </div>
        </div>

        <div class="card shadow-sm bg-white mb-5">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-table"></i> Tabellone Turni Mensile (<?php echo "$nomeMeseCorrente $anno_selezionato"; ?>)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-turni mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="col-operatore-sticky bg-dark text-white">Collaboratore</th>
                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++): 
                                    $timestampGiorno = mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato);
                                    $numGiornoSettimana = date('N', $timestampGiorno);
                                    $letteraGiorno = ['1'=>'L', '2'=>'M', '3'=>'M', '4'=>'G', '5'=>'V', '6'=>'S', '7'=>'D'][$numGiornoSettimana];
                                    $isFestivo = ($numGiornoSettimana == 7); // Domenica
                                ?>
                                    <th class="<?php echo $isFestivo ? 'bg-danger text-white' : ''; ?>">
                                        <div><?php echo $g; ?></div>
                                        <div style="font-size: 0.55rem; opacity: 0.8;"><?php echo $letteraGiorno; ?></div>
                                    </th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaCollaboratori)) { ?>
                                <tr>
                                    <td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center py-4 text-muted">
                                        Nessun collaboratore trovato per la struttura o reparto selezionato.
                                    </td>
                                </tr>
                            <?php } else { ?>
                                <?php foreach ($listaCollaboratori as $collab) { 
                                    $uId = $collab['id'];
                                    $nomeCollab = $collab['nome'] ?? 'Utente';
                                    $qualificaCollab = trim($collab['qualifica'] ?? '') !== '' ? $collab['qualifica'] : ($collab['ruolo'] ?? '');
                                ?>
                                    <tr>
                                        <td class="col-operatore-sticky">
                                            <div class="text-truncate fw-bold text-dark" style="max-width: 170px; font-size: 0.78rem;" title="<?php echo htmlspecialchars($nomeCollab); ?>">
                                                <?php echo htmlspecialchars($nomeCollab); ?>
                                            </div>
                                            <div style="font-size: 0.62rem; color: #6c757d;" class="text-truncate">
                                                <?php echo htmlspecialchars($qualificaCollab); ?>
                                            </div>
                                        </td>
                                        <?php for ($g = 1; $g <= $giorni_nel_mese; $g++): 
                                            $giornoStamp = sprintf('%04d-%02d-%02d', $anno_selezionato, $mese_selezionato, $g);
                                            $turniGiornoCell = $mappaTurniGriglia[$uId][$g] ?? [];
                                        ?>
                                            <td class="cella-interattiva" onclick="apriModaleCella('<?php echo $uId; ?>', '<?php echo htmlspecialchars($nomeCollab); ?>', '<?php echo $giornoStamp; ?>', '<?php echo htmlspecialchars(json_encode($turniGiornoCell), ENT_QUOTES, 'UTF-8'); ?>')">
                                                <?php if (!empty($turniGiornoCell)) { 
                                                    foreach ($turniGiornoCell as $ev) {
                                                        $codEv = strtoupper(trim($ev['tipo_evento']));
                                                        $coloreBadge = $mappaColoriTurni[$codEv] ?? '#6c757d';
                                                        $statoEv = $ev['stato'] ?? 'Approvato';
                                                        $classeInAttesa = (strcasecmp($statoEv, 'In attesa') === 0 || strcasecmp($statoEv, 'Pending') === 0) ? 'badge-in-attesa' : '';
                                                ?>
                                                        <span class="badge-turno <?php echo $classeInAttesa; ?>" style="background-color: <?php echo $coloreBadge; ?>;" title="<?php echo htmlspecialchars($codEv . ' (' . $statoEv . ')'); ?>">
                                                            <?php echo htmlspecialchars($codEv); ?>
                                                        </span>
                                                <?php 
                                                    }
                                                } else { ?>
                                                    <span class="text-muted opacity-25" style="font-size: 0.6rem;">·</span>
                                                <?php } ?>
                                            </td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Modal per gestione singola cella -->
    <div class="modal fade" id="modaleCella" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="turni.php?mese=<?php echo $mese_selezionato; ?>&anno=<?php echo $anno_selezionato; ?>&reparto_id=<?php echo $reparto_selezionato; ?>">
                    <div class="modal-header">
                        <h5 class="modal-title fs-6 fw-bold" id="titoloModaleCella">Gestione Turno Giorno</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="azione" id="azioneModaleInput" value="aggiungi_turno_cella">
                        <input type="hidden" name="utente_id" id="modaleUtenteId">
                        <input type="hidden" name="data" id="modaleDataGiorno">
                        <input type="hidden" name="turno_id" id="modaleTurnoId">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Seleziona Turno / Evento</label>
                            <select class="form-select form-select-sm" name="valore" id="modaleValoreTurno" required>
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($tipologieTurni as $t) { ?>
                                    <option value="<?php echo htmlspecialchars($t['codice_breve']); ?>">
                                        <?php echo htmlspecialchars($t['nome_turno']); ?> (<?php echo htmlspecialchars($t['codice_breve']); ?>)
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="mb-3" id="divStatoTurno" style="display:none;">
                            <label class="form-label small fw-bold">Stato Approvazione</label>
                            <select class="form-select form-select-sm" name="stato" id="modaleStatoTurno">
                                <option value="Approvato">Approvato</option>
                                <option value="In attesa">In attesa</option>
                                <option value="Rifiutato">Rifiutato</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="submit" name="azione_btn" value="cancella" class="btn btn-outline-danger btn-sm" id="btnCancellaCella" style="display:none;" onclick="document.getElementById('azioneModaleInput').value='cancella_turno_cella';">Cancella Turno</button>
                        <div class="ms-auto d-flex gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Chiudi</button>
                            <button type="submit" class="btn btn-primary btn-sm" id="btnSalvaCella">Salva</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function apriModaleCella(utenteId, nomeOperatore, dataGiorno, eventiJson) {
            document.getElementById('modaleUtenteId').value = utenteId;
            document.getElementById('modaleDataGiorno').value = dataGiorno;
            document.getElementById('titoloModaleCella').innerText = "Turno di " + nomeOperatore + " - " + dataGiorno;

            let eventi = [];
            try {
                eventi = JSON.parse(eventiJson);
            } catch(e) {
                eventi = [];
            }

            const azioneInput = document.getElementById('azioneModaleInput');
            const selectValore = document.getElementById('modaleValoreTurno');
            const selectStato = document.getElementById('modaleStatoTurno');
            const divStato = document.getElementById('divStatoTurno');
            const btnCancella = document.getElementById('btnCancellaCella');
            const inputTurnoId = document.getElementById('modaleTurnoId');

            if (eventi.length > 0) {
                azioneInput.value = 'modifica_turno_cella';
                inputTurnoId.value = eventi[0].id;
                selectValore.value = eventi[0].tipo_evento;
                selectStato.value = eventi[0].stato || 'Approvato';
                divStato.style.display = 'block';
                btnCancella.style.display = 'inline-block';
            } else {
                azioneInput.value = 'aggiungi_turno_cella';
                inputTurnoId.value = '';
                selectValore.value = '';
                selectStato.value = 'Approvato';
                divStato.style.display = 'none';
                btnCancella.style.display = 'none';
            }

            var myModal = new bootstrap.Modal(document.getElementById('modaleCella'));
            myModal.show();
        }
    </script>
</body>
</html>
