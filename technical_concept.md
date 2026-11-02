# adaptive_video – Architektur und technische Spezifikation

**Status:** Architekturentwurf, ADR-001 bis ADR-017 angenommen  
**Zielplattform:** TYPO3 v13 LTS und v14 (keine v12-Kompatibilität)  
**Extension-Key:** `adaptive_video`  
**PHP-Namespace:** `Mfd\AdaptiveVideo`  
**Composer-Paket:** `mfd/adaptive-video`  
**Repository:** `github.com/marketing-factory/adaptive_video`  
**Abhängigkeiten:** `b13/poster-image` `^2.0` (TYPO3 v13/v14)  
**Stand:** 27. Oktober 2026

## 1. Ziel und Umfang

`adaptive_video` erweitert die bestehende TYPO3-FAL-Videonutzung um automatisch erzeugte adaptive Streams. Redakteurinnen und Redakteure laden ein Originalvideo in FAL hoch und verwenden es in bestehenden Medienfeldern bzw. Content Elements. Sobald eine unterstützte FAL-Referenz erstmals verwendet wird, wird asynchron eine Verarbeitung angestoßen. Solange kein gültiger Stream verfügbar ist, bleibt der reguläre TYPO3-Videorenderer mit dem Originalvideo aktiv. Nach erfolgreicher Verarbeitung wird auf HLS umgeschaltet.

**Warum HLS:** Zielinhalte sind vor allem lange Videos (Tutorials, Webcasts). Adaptives Streaming lädt nur, was tatsächlich angesehen wird, erlaubt Springen ohne Volldownload und passt die Qualität an schwankende Verbindungen an. Für kurze Clips/Loops wären progressive MP4-Varianten ausreichend; sie sind als mögliches zweites Ausgabeformat denkbar, aber nicht Ziel des MVP.

**MVP:** lokales natives ffmpeg/ffprobe, H.264/AAC, CMAF-kompatibles HLS (fMP4), hls.js mit nativer HLS-Wiedergabe wo verfügbar, lokale Artefakt-Ablage (`_videos`), TYPO3 Message Bus, ein globales Standardprofil, Site Settings für Aktivierung/Player und CLI-Verwaltung. **Später:** AWS MediaConvert, DASH, alternative Player, mehrsprachige Audiospuren, Untertitel, erweitertes Backend-Modul.

## 2. Verbindliche Architekturentscheidungen

| ADR | Entscheidung |
| --- | --- |
| 001 | Verarbeitung bei erster Verwendung einer unterstützten FAL-Referenz, nicht beim Upload oder Frontend-Aufruf. Doppelte Aufträge vermeiden. |
| 002 | Erster Provider: lokal installiertes natives ffmpeg; später AWS MediaConvert über Provider-Abstraktion. |
| 003 | Jeder Provider besitzt ein konfigurierbares Ausgabeziel; physische Ablage und öffentliche URL/CDN-Auflösung bleiben getrennt. |
| 004 | Asynchrone Verarbeitung ausschließlich über TYPO3 Message Bus / Symfony Messenger auf einem eigenen Doctrine-Transport `adaptive_video`; keine eigene Queue-Implementierung oder Retry-Engine. Keine automatischen Wiederholungen bei Fehlern; abgebrochene Worker werden über Redelivery + Timeout-Übernahme aufgefangen. |
| 005 | Versionierung ohne Versionszeiger: neue Artefakte neben bestehenden erstellen, prüfen und durch `status = ready` freigeben. **Aktiv** ist die neueste `ready`-Version, deren `source_fingerprint` zum aktuellen `sys_file.sha1` passt. Alte Versionen werden im MVP nicht automatisch entfernt. |
| 006 | Original und redaktionelle Metadaten in FAL belassen. Kein eigenes Posterfeld: Die Extension setzt `b13/poster-image` (`^2.0`) voraus und nutzt dessen Feld `sys_file_metadata.poster`. |
| 007 | Konfigurierbare Encoding-Profile; niemals Upscaling; Seitenverhältnis und Hochformat beachten. |
| 008 | Verarbeitung pro Datei, nicht pro Referenz. **Hauptweg ist der regelmäßige Scan** (`adaptive-video:scan`, als Scheduler-Task, mit `--dry-run`) über `sys_file_reference` unabhängig von der Ursprungstabelle. Der Scan versendet nur Messages und kodiert nicht selbst. Zusätzlich reiht ein DataHandler-Hook Videos sofort beim Speichern ein. |
| 009 | Processing, Freigabe und Cache-Invalidierung fachlich trennen. Invalidierung über einen `CacheInvalidator`-Service (direkter Aufruf, `try/catch` + Logging), **keine eigene Message** im MVP. Fehler bei der Invalidierung führen nicht zu erneutem Encoding; die Cache-TTL ist Rückfallebene. |
| 010 | Originalvideo als Fallback bei fehlendem Stream. Bei Neuverarbeitung wegen **geänderten Profils** bleibt die bisherige Version aktiv, bis die neue `ready` ist; bei **geändertem Quellinhalt** passt kein `source_fingerprint` mehr, es wird automatisch das Original ausgeliefert. |
| 011 | MVP: eine bevorzugte/erste unterstützte Audiospur, AAC-LC; stumme Videos erlaubt; mehrsprachige Audiospuren später. |
| 012 | CMAF-kompatible fMP4-Artefakte und HLS im MVP; Packaging so auslegen und testen, dass DASH später möglichst dieselben Segmente nutzen kann. |
| 013 | hls.js als einziger integrierter JS-Player im MVP; native HLS-Unterstützung bevorzugen; Player-Austausch auf Roadmap. |
| 014 | Provider, Infrastruktur und Profile global; im MVP **ein globales Standardprofil**. Site Settings steuern nur Aktivierung und Player-Verhalten, keine Profilwahl. Profile pro Site/Datei: Roadmap. |
| 015 | Generierte Streaming-Artefakte **nicht über FAL**, sondern über ein schmales `ArtifactStorageInterface`. MVP: lokales, konfigurierbares Verzeichnis im Web-Root (Standard `public/_videos`, analog zu `_assets`). S3 erst zusammen mit einem AWS-Provider (Roadmap). |
| 016 | Technische Tabellen ausschließlich über `ext_tables.sql` inkl. Unique-Indizes; kein TCA, Zugriff per QueryBuilder. |
| 017 | Die Extension wird veröffentlicht. Sie enthält nur allgemein nutzbare Funktionalität; projektspezifische Anpassungen (CSP, Templates, Deployment, Hosting) gehören ins jeweilige Projekt. |

## 3. Laufzeitablauf

1. Der regelmäßige Scan ermittelt unterstützte FAL-Dateien mit aktiven Referenzen, für die keine Version zum aktuellen `sys_file.sha1` und Profil-Fingerprint existiert.
2. Für jede solche Datei wird per `INSERT` eine Version mit `status = pending` angelegt (Unique-Index verhindert Duplikate) und `ProcessVideoMessage(versionUid)` an den Transport `adaptive_video` gesendet.
3. Ein Worker übernimmt die Version per bedingtem Update (`pending` → `processing`), ermittelt per `ffprobe` die Eigenschaften und führt `ffmpeg` mit dem Profil-Snapshot in einem temporären Arbeitsverzeichnis aus.
4. Die Artefakte werden geprüft und unter einem neuen, unveränderlichen Versionspräfix im Artefaktverzeichnis veröffentlicht.
5. Erst danach wird die Version auf `ready` gesetzt. Damit ist sie aktiv; ein separater Umschaltschritt entfällt.
6. Der Worker ruft den `CacheInvalidator` auf (Tag `adaptivevideo_file_<fileUid>`). Ein Fehler wird geloggt und löst kein erneutes Encoding aus.
7. Der Renderer liefert die aktive Version als HLS aus, sonst das Originalvideo.

**Caching:** Versions-URLs sind unveränderlich und CDN-cachebar. Bereits laufende Sessions können weiter auf die vorige Version zugreifen, da alte Versionen im MVP nicht gelöscht werden.

Warum eine eigene Invalidierung nötig ist, obwohl TYPO3 Seiten-Caches beim Bearbeiten von Datensätzen selbst leert:

- Der DataHandler leert nur den Cache der Seite des geänderten Datensatzes (`pageId_<pid>`). Die Freigabe einer Version (`ready`) und das Ersetzen einer Datei laufen aber **außerhalb des DataHandlers** (Worker bzw. FAL-Event).
- Beim Ersetzen einer Datei leert der Core keine Seiten-Caches (`FileDeletionAspect` entfernt nur Processed Files). Für normale Videos ist das unkritisch, weil die URL gleich bleibt. Unser HTML enthält dagegen **versionierte URLs**; veraltetes HTML zeigt aktiv auf den alten Inhalt.
- Ein Video kann auf mehreren Seiten eingebunden sein; der DataHandler würde nur eine davon treffen.

Umsetzung:

- Der Renderer setzt **immer** – auch bei Ausgabe des Originals – das Cache-Tag `adaptivevideo_file_<fileUid>` über den `CacheDataCollector` des Requests (`frontend.cache.collector`). Da `FileRendererInterface::render()` keinen Request erhält, im MVP über `$GLOBALS['TYPO3_REQUEST']`; in v14 zu prüfen.
- `CacheInvalidator::flush(fileUid)` → `CacheManager::flushCachesInGroupByTags('pages', ['adaptivevideo_file_<fileUid>'])`.
- Aufrufe: nachdem eine Version `ready` ist (Worker) und beim Ersetzen einer Datei (`AfterFileReplacedEvent`, synchron im auslösenden Request), damit gecachte Seiten nicht weiter auf die Version des alten Inhalts zeigen.
- Externe Caches (Reverse-Proxy, CDN) leert die Extension nicht selbst. Wer Tag-Flushes des Page-Caches an den Proxy weiterreicht, ist automatisch abgedeckt; das wird in der Extension-Dokumentation beschrieben.

**Kein explizites Deaktivieren:** Eine Version wird nie ausgeschaltet. Sie ist nur dann aktiv, wenn ihr `source_fingerprint` zur Datei passt; bei geändertem Inhalt ist sie damit automatisch nicht mehr aktiv. An CE, Referenz und Datei ändert sich nichts.

## 4. Datenmodell

Eine einzige Tabelle. Bei nur einem globalen Profil entspricht ein „Asset“ einer Datei; ein eigener Asset-Datensatz mit Versionszeiger ist nicht nötig.

### `tx_adaptivevideo_version`

| Feld (konzeptionell) | Bedeutung |
| --- | --- |
| `uid` | Primärschlüssel |
| `file_uid` | Referenz auf `sys_file` |
| `source_fingerprint` | `sys_file.sha1` der Quelle zum Zeitpunkt der Anlage |
| `profile_fingerprint` | Hash des Profil-Snapshots |
| `profile_snapshot` | Tatsächlich verwendete, unveränderliche Profilparameter (JSON) |
| `output_prefix` | Versionierter Speicherpfad relativ zum Artefaktverzeichnis; das Master-Manifest liegt immer unter `<output_prefix>/master.m3u8` |
| `status` | `pending`, `processing`, `ready`, `failed`, `skipped` (z. B. unter Mindestdauer) |
| `processing_started_at` | Zeitpunkt der Übernahme durch einen Worker; Grundlage für die Timeout-Übernahme verwaister Jobs |
| `error_message` | Letzte Fehlermeldung bei `failed`; Details im TYPO3-Logging |
| `created_at`, `completed_at` | Zeitstempel |

**Aktive Version** einer Datei:

```sql
SELECT … FROM tx_adaptivevideo_version
WHERE file_uid = ? AND source_fingerprint = <sys_file.sha1> AND status = 'ready'
ORDER BY uid DESC LIMIT 1
```

- Profiländerung: Die neue Version bekommt einen höheren `uid`; bis sie `ready` ist, bleibt die bisherige die neueste fertige (ADR-010).
- Geänderter Inhalt: Keine Version passt mehr zum neuen `sha1`, der Renderer liefert das Original, bis die neue Version fertig ist.
- `skipped` und `failed` verhindern, dass der Scan dieselbe Kombination immer wieder einreiht. Für einen erneuten Versuch wird die Zeile gelöscht.

Später bei Bedarf ergänzbar, ohne die Struktur zu ändern: `profile_identifier` (mehrere Profile), `provider_identifier`/`provider_job_id` (AWS), `dash_manifest_path` (DASH).

**Kein Processing-Log-Tabelle:** Fehler stehen in `error_message`, Details (inkl. ffmpeg-Ausgabe, gekürzt) im TYPO3 Logging Framework.

### Schema und Konkurrenzsicherheit

Die Tabelle wird ausschließlich in **`ext_tables.sql`** definiert (ADR-016). **Kein TCA**: keine Formulare, keine Listenansicht, kein `pid`; Zugriff ausschließlich über den QueryBuilder (vgl. Core-Tabellen wie `sys_messenger_messages`, `sys_http_report`). TCA-basierte Schema-Generierung scheidet aus, weil sie keine zusammengesetzten Unique-Indizes erzeugt.

```sql
CREATE TABLE tx_adaptivevideo_version (
    UNIQUE KEY version_identity (file_uid, source_fingerprint, profile_fingerprint),
    KEY lookup (file_uid, status)
);
```

1. **Anlage:** `INSERT` versuchen; bei `UniqueConstraintViolationException` existiert die Version bereits, nichts zu tun.
2. **Übernahme durch einen Worker:** `UPDATE … SET status = 'processing', processing_started_at = now WHERE uid = ? AND (status = 'pending' OR (status = 'processing' AND processing_started_at < now - maxRuntime))`; nur bei `affectedRows === 1` fortfahren. Der zweite Zweig übernimmt Versionen abgestürzter Worker.
3. **Freigabe:** `UPDATE … SET status = 'ready', completed_at = now WHERE uid = ? AND status = 'processing'` – eine einzelne Zeile, damit atomar.

Keine expliziten Locks, Transaktionen über mehrere Tabellen oder `SELECT … FOR UPDATE` erforderlich.

**Komplett neu verarbeiten** (Notfall, z. B. nach Profilfehler oder inkonsistentem Artefaktverzeichnis): Artefaktverzeichnis leeren, Tabelle leeren, Scan ausführen. Alle Videos werden neu eingereiht; bis zur Fertigstellung wird das Original ausgeliefert.

## 5. Geplante Paketstruktur

```text
adaptive_video/
├── Classes/
│   ├── Command/
│   │   ├── ScanCommand.php
│   │   ├── ProcessCommand.php
│   │   └── StatusCommand.php
│   ├── Domain/
│   │   ├── Model/
│   │   └── Repository/
│   ├── Processing/
│   │   ├── VideoProcessingService.php
│   │   ├── ProcessingProviderInterface.php
│   │   ├── ProcessingRequest.php
│   │   ├── ProcessingResult.php
│   │   ├── EncodingProfile.php
│   │   ├── SourceAnalyzer.php
│   │   ├── ArtifactValidator.php
│   │   └── Provider/FfmpegProvider.php
│   ├── Storage/
│   │   ├── ArtifactStorageInterface.php
│   │   └── LocalFilesystemStorage.php
│   ├── Messenger/
│   │   ├── Message/ProcessVideoMessage.php
│   │   └── Handler/
│   ├── EventListener/FileReplacedListener.php
│   ├── Hooks/DataHandlerHook.php
│   └── Resource/Rendering/AdaptiveVideoRenderer.php
├── Configuration/
│   ├── Services.yaml
│   └── Sets/
├── Resources/
│   ├── Public/JavaScript/
│   └── Private/
├── Tests/
│   ├── Unit/
│   ├── Functional/
│   └── Integration/
├── composer.json
├── ext_tables.sql
├── ext_emconf.php
└── docs/architecture.md
```

Die Struktur ist eine Zielskizze, keine Verpflichtung, für jede Schicht schon im MVP eine Klasse zu erzeugen. Keine unnötigen Abstraktionen.

Composer-Autoload:

```json
{
  "autoload": {
    "psr-4": {
      "Mfd\\AdaptiveVideo\\": "Classes/"
    }
  }
}
```

## 6. Provider, Message Bus und Storage

### Processing-Provider

Konzeptionelles Interface:

```php
namespace Mfd\AdaptiveVideo\Processing;

interface ProcessingProviderInterface
{
    public function getIdentifier(): string;

    public function start(ProcessingRequest $request): ProcessingResult;
}
```

Für lokale Verarbeitung kann `start()` synchron bis zum Ergebnis laufen, **innerhalb eines asynchronen Messenger-Consumers**. Ein externer Provider kann stattdessen eine externe Job-ID zurückgeben; Abschluss und Aktivierung bleiben zentrale Verantwortung des Processing-Service. AWS-spezifische Parameter gehören nicht ins allgemeine Encoding-Profil.

**Umsetzung im MVP (Phase 3):** Das Interface wird erst mit dem zweiten Provider eingeführt, damit es sich an dessen tatsächlichem Ablauf ausrichtet. Bis dahin ruft der `VideoProcessingService` direkt `FfmpegEncoder` (Encoding und Packaging in einem Lauf), `ArtifactValidator` und `ArtifactStorageInterface` auf.

### Messages

- `ProcessVideoMessage(assetVersionUid)`

Messages transportieren IDs, keine FAL-Objekte oder Binärdaten. Handler sind idempotent.

**Transport und Worker (verifiziert gegen TYPO3 v13.4):**

- Der Core stellt `default` (synchron) und `doctrine` (Tabelle `sys_messenger_messages`, `queue_name: default`) bereit. Die Extension definiert in ihrer `Services.yaml` einen **eigenen Doctrine-Transport `adaptive_video`** mit eigenem `queue_name` und konfigurierbarem `redeliver_timeout`, damit lange Encodings keine anderen asynchronen Nachrichten blockieren. Worker: `typo3 messenger:consume adaptive_video`.
- **Kein Routing über `SYS/messenger/routing`**, sondern expliziter `TransportNamesStamp` beim Versand: Der `TransportLocator` des Core (v13.4) kennt anders als Symfony keinen Fallback für `'*'` und würde die Message zusätzlich an `default` (synchron) senden, also sofort im Scan-Prozess verarbeiten (verifiziert per Functional-Test).
- Der Transport wird von einer eigenen Factory erzeugt (die Core-`DoctrineTransportFactory` ist `@internal`). Sie castet den DB-Port auf `int` wie der `ConnectionPool`; ein per `getenv()` gesetzter Port (String) lässt den mysqli-Treiber sonst scheitern – betrifft auch den Core-Transport `doctrine`.
- Der Core registriert **keine Retry- und keine Failure-Transport-Listener**. Wirft ein Handler eine Exception, wird die Nachricht verworfen. Fehler werden über `status = failed` und `error_message` sowie das TYPO3-Logging festgehalten. Für einen erneuten Versuch wird die Version gelöscht; der nächste Scan reiht die Datei neu ein.
- Bricht ein Worker während des Encodings ab (OOM, Deployment, Neustart), bleibt die Nachricht unbestätigt und wird nach `redeliver_timeout` erneut ausgeliefert. Die Timeout-Übernahme (siehe Reservierungsmuster, §4) erlaubt dann die Wiederaufnahme. Voraussetzung: **`maxRuntime` (ffmpeg-Timeout via Symfony Process) < `redeliver_timeout`**.
- Läuft ein Encoding länger als `redeliver_timeout`, wird die Nachricht doppelt ausgeliefert; das bedingte Übernahme-Update verhindert die Doppelverarbeitung.
- **Worker auf anderem Host:** Der Doctrine-Transport erlaubt Worker außerhalb des Webservers (nur DB-Zugriff nötig). Mit dem lokalen Artefakt-Storage braucht der Worker aber Lesezugriff auf die Originale und Schreibzugriff auf das Artefaktverzeichnis, also ein gemeinsames Dateisystem. Ohne gemeinsames Dateisystem erst mit einem S3-Storage.
- Der Worker muss nach jedem Deployment neu starten, ohne laufende Encodings hart abzubrechen; Details siehe Worker-Betrieb (§10).
- `typo3 messenger:consume` kennt in v13.4 **keine** Optionen `--time-limit`/`--limit`/`--memory-limit` (nur `--sleep`, `--queues`, `--exit-code-on-limit`). Das Zeitlimit setzt der Core-Service `StopWorkerOnTimeLimitListener` (3600 s, greift erst nach Abschluss der laufenden Nachricht); änderbar nur per DI im Projekt.

### Artefakt-Storage

Artefakte werden **nicht über FAL** abgelegt (ADR-015). Gründe: Alle öffentlichen Schreibmethoden von `ResourceStorage` indexieren in `sys_file`; ein Umgehen über den Driver würde Rechte, Events und Processing aushebeln; der Indexer-Task bzw. das Öffnen eines Ordners in der Dateiliste würde nachträglich tausende Segmente indexieren.

Schmale Abstraktion:

```php
namespace Mfd\AdaptiveVideo\Storage;

interface ArtifactStorageInterface
{
    public function publish(string $localDirectory, string $prefix): void;
    public function delete(string $prefix): void;
    public function getPublicUrl(string $path): string;
}
```

`exists()` entfällt im MVP: Das Rendering prüft die Existenz der Artefakte bewusst nicht (§8).

**MVP: `LocalFilesystemStorage`.** Der ffmpeg-Provider verarbeitet in einem temporären Arbeitsverzeichnis und veröffentlicht anschließend per `rename()` unter einem neuen Versionspräfix im konfigurierten Zielverzeichnis. Liegen Arbeits- und Zielverzeichnis auf demselben Dateisystem, ist die Veröffentlichung atomar; andernfalls wird zuerst in ein verstecktes Verzeichnis neben dem Ziel kopiert und dann umbenannt.

- **Versionspräfix:** `<fileUid>/<versionUid>`. Die Versions-UID ist eindeutig, damit ist der Pfad nie wiederverwendet. Ein vorhandenes Verzeichnis unter dem Präfix stammt von einem abgebrochenen Versuch derselben, noch nicht freigegebenen Version und wird ersetzt.
- Das Zielverzeichnis darf ein Symlink sein (z. B. Shared Directory des Deployments); geprüft wird nur, dass der konfigurierte Pfad relativ zum Web-Root ohne `..` ist.
- Dateirechte werden nach dem Veröffentlichen per `GeneralUtility::fixPermissions()` gesetzt (`fileCreateMask`/`folderCreateMask`).

- Zielverzeichnis konfigurierbar, Standard `_videos` relativ zum Web-Root (neben `_assets`, aber **nicht darin**, da `_assets` vom Composer-Installer verwaltet wird).
- Der Pfad muss innerhalb des Web-Roots liegen und wird beim Laden der Konfiguration validiert (kein `..`).
- Öffentliche URL = konfigurierbares URL-Präfix (Standard `/_videos/`) + relativer Pfad. Ein vorgeschaltetes CDN ist für die Extension transparent.
- Versionspfade sind unveränderlich und dürfen mit langer Cache-Dauer (`immutable`) ausgeliefert werden; Playlists ebenso, da sich ihr Pfad je Version ändert.
- Der Webserver muss `.m3u8` (`application/vnd.apple.mpegurl`) und `.m4s` (`video/iso.segment`) mit korrekten MIME-Typen ausliefern; `.m4s` fehlt in vielen Standardkonfigurationen. Die Extension dokumentiert Beispielkonfigurationen für Apache und nginx.
- Dauerhaftigkeit (Shared Directory beim Deployment, Backup, Umgebungs-Sync) ist Aufgabe des Projekts.

Beispiel:

```text
_videos/
└── 123/            (fileUid)
    └── 45/         (versionUid)
        ├── master.m3u8
        ├── audio/
        │   ├── playlist.m3u8
        │   ├── init_4.mp4      (Index des Streams im ffmpeg-Lauf)
        │   └── segment_00000.m4s
        ├── 1080p/
        ├── 720p/
        ├── 480p/
        └── 360p/
```

## 7. Encoding und Packaging

### Standardprofil (Ausgangswerte, anpassbar)

| Zielhöhe | Video-Bitrate, ca. |
| --- | ---: |
| 1080p | 5.000 kbit/s |
| 720p | 2.800 kbit/s |
| 480p | 1.400 kbit/s |
| 360p | 800 kbit/s |

- H.264/AVC; AAC-LC, standardmäßig 128 kbit/s und 48 kHz.
- Höchstens 30 fps im Standardprofil.
- Segmentdauer standardmäßig 4 Sekunden.
- fMP4/CMAF, `.mp4`-Initialisierungssegmente und `.m4s`-Mediensegmente.
- Separate, gemeinsam verwendete Audiorendition; keine künstliche Audiospur bei stummen Videos.
- Gleiche Segmentgrenzen und geeignete Keyframe-/GOP-Struktur für alle Renditions.
- **Kein Upscaling**: `ffprobe` bestimmt Dimensionen und Orientierung. Nur Renditions erzeugen, deren Zielabmessungen die Quelle nicht vergrößern; Seitenverhältnis und codec-kompatible Maße bewahren.
  - Die Zielhöhe bezieht sich auf die **kürzere Seite**: Ein Hochformat-Video 1080x1920 erhält „1080p“ in 1080x1920.
  - Maße werden auf gerade Werte gerundet (H.264 mit 4:2:0), nie über die Quelle hinaus.
  - Liegt die Quelle unter der kleinsten Rendition, entsteht genau eine Rendition in Quellgröße mit der kleinsten Bitrate.
  - Gedrehte Handy-Videos: ffmpeg dreht beim Dekodieren automatisch; die Ausgabe hat die Anzeigemaße und keine Rotations-Metadaten mehr.
- Konstante Bildrate per `fps`-Filter (Quelle, höchstens `maxFrameRate`), feste GOP (`-g` = Bildrate × Segmentdauer, `-sc_threshold 0`, `-force_key_frames`), dadurch identische Segmentgrenzen.
- Bitraten je Rendition: `maxrate` 110 %, `bufsize` 200 % der Zielbitrate; Audio als Stereo-Downmix (`-ac 2`).
- Bevorzugt die als Standard markierte Audiospur, sonst die erste unterstützte.

Die spätere DASH-Wiederverwendung identischer Segmente ist ein **Packaging- und Testziel**, keine allein durch fMP4 garantierte Eigenschaft.

### ffmpeg-Aufruf und Ressourcen

- **Ein ffmpeg-Aufruf pro Version** (`-filter_complex split`): Quelle wird einmal dekodiert, alle Renditions entstehen in einem Lauf. Spart CPU und hält Keyframes/Segmentgrenzen der Renditions automatisch synchron (ADR-012).
- **Konfigurierbarer Aufruf** (global, Extension-Konfiguration):
  - `ffmpegPath`, `ffprobePath`: Pfade zu den Binaries.
  - `commandPrefix`: Präfix-Argumente vor dem Binary, **Standard `nice -n 19`**; leer lassen, wenn nicht verfügbar.
  - `ffmpegOptions`: zusätzliche Optionen, **Standard `-threads 1`** (z. B. ergänzbar um `-preset`). Sie stehen als Ausgabeoptionen vor dem HLS-Ausgang, wirken also auf alle Encoder; der Decoder wählt seine Threads weiterhin selbst.
  - Alle Werte werden als Argumentliste an Symfony Process übergeben, nie als Shell-String. Die Konfiguration gilt als vertrauenswürdig (nur Administratoren); Optionen, die Ein-/Ausgabepfade, Codecs oder Segmentierung betreffen, erzeugt die Extension selbst aus dem Profil und dürfen nicht überschrieben werden.
  - Optionen, die das Ergebnis beeinflussen (z. B. `-preset`), fließen in den `profile_fingerprint` ein; reine Laufzeitoptionen (`-threads`, Präfix) nicht.
- `maxRuntime` (Timeout via Symfony Process) < `redeliver_timeout` (siehe §6). Beide Werte sind **statisch** konfiguriert und müssen für das längste erwartete Video ausreichen (lange Webcasts können bei `-threads 1` mehrere Stunden dauern). Keine dynamische Anpassung von Laufzeit oder ffmpeg-Optionen je Video im MVP; bei Bedarf mehr Threads bzw. CPU über die Konfiguration. Überschreitet ein Job `maxRuntime`, endet er als `failed`.
- Bei genau einem Worker blockiert ein langes Encoding die Queue für nachfolgende Videos; das wird im MVP bewusst in Kauf genommen.
- Konfigurierbare Obergrenzen für Quellgröße bzw. -dauer, damit einzelne sehr große Dateien den Worker nicht stundenlang blockieren.
- **Temporäres Arbeitsverzeichnis** konfigurierbar, auf demselben Dateisystem wie das Artefaktverzeichnis (atomares `rename()`). Vor Start Prüfung des freien Speichers im Arbeits- und im Artefaktverzeichnis; geschätzt aus Dauer × Summe der Zielbitraten × 1,5 (die Quellgröße taugt nicht als Maß, da die Ausgabe bei stark komprimierten Quellen größer sein kann). Bei zu wenig Platz `failed` mit eindeutigem Fehlercode. Das Arbeitsverzeichnis `version_<uid>` wird nach jedem Lauf entfernt, auch bei Fehlern.
- **Profiländerung zwischen Scan und Verarbeitung:** Passt der Fingerprint des aktuellen Profils nicht mehr zur Version, wird sie `skipped`; der nächste Scan legt eine Version für das neue Profil an. So entspricht der gespeicherte Snapshot immer dem tatsächlichen Encoding.
- **Parallelität** steuert der Betrieb: im MVP **genau ein Worker** für den Transport `adaptive_video`. Eine eigene Begrenzung in der Extension ist nicht vorgesehen, da ohne Retry (§6) zurückgestellte Nachrichten verloren gingen.

### Validierung vor Aktivierung

- Master- und Varianten-Playlists sind vorhanden und syntaktisch korrekt.
- Sämtliche referenzierten Init- und Mediensegmente existieren.
- `ffprobe` auf Init- und erstes Mediensegment je Rendition: Codec, Auflösung und Dauer entsprechen dem Profil.
- Segmentausrichtung im MVP nur über den Vergleich der `#EXTINF`-Dauern der Varianten-Playlists; sie ist durch den gemeinsamen ffmpeg-Lauf mit festem GOP konstruktionsbedingt gegeben.
- Umsetzung: `ffprobe` liest die Varianten-Playlist direkt (HLS-Demuxer) und liefert Codec, Auflösung und Gesamtdauer. Toleranz der Dauer gegenüber der Quelle: eine Segmentdauer bzw. 1 %, da die Tonspur einer Quelle länger sein kann als das Bild.
- Segment-URIs in Playlists müssen einfache Dateinamen sein; nichts darf aus dem Versionsverzeichnis herauszeigen.
- Vollständige Zeitstempelanalyse und Demux-/Wiedergabeprüfung erst mit DASH (Roadmap).
- Erst nach vollständiger Veröffentlichung und erfolgreicher Prüfung wird die Version aktiv.

## 8. TYPO3-Integration und Player

### Erkennung

Verarbeitet wird **pro Datei**. Kopien, Übersetzungen und Workspace-Versionen von Referenzen auf eine bereits bekannte Datei erfordern daher keine Aktion. Keine Verarbeitung im Frontend-Request.

| Auslöser | Rolle |
| --- | --- |
| `adaptive-video:scan` (Scheduler-Task) | **Hauptweg.** Findet alle unterstützten Dateien mit aktiven Referenzen, unabhängig von `tablenames`, `uid_foreign` und `fieldname`; erfasst auch Importe, Migrationen und Schreibzugriffe außerhalb des DataHandlers. |
| DataHandler-Hook (`processDatamap_afterAllOperations`, `processCmdmap_afterFinish`) | **Beschleunigung.** Reiht die Dateien gespeicherter, kopierter und lokalisierter `sys_file_reference`-Datensätze sofort ein, mit denselben Regeln wie der Scan (`FileScanner` mit Datei-Filter). Fehler beim Einreihen werden geloggt und brechen das Speichern nicht ab; der nächste Scan holt sie nach. |
| `AfterFileReplacedEvent` | Nur Cache-Flush für die Datei, damit gecachte Seiten nicht weiter die Version des alten Inhalts ausliefern. Die Neuverarbeitung übernimmt der nächste Scan (neuer `sha1`). |

**Unterstützte Dateien:**

- Konfigurierbare Liste von Dateiendungen/MIME-Typen (Standard z. B. `mp4`, `mov`, `m4v`, `webm`).
- Ausdrücklich ausgeschlossen: TYPO3-Online-Medien (`.youtube`, `.vimeo` u. Ä.).
- Konfigurierbare Mindestdauer, **Standard 10 Sekunden**; kürzere Videos werden nicht verarbeitet und bleiben beim Original. Die Dauer wird per `ffprobe` im Worker ermittelt; zu kurze Dateien werden als `skipped` markiert, damit der Scan sie nicht erneut einreiht.

**Nicht im MVP:** Listener für `AfterFileContentsSetEvent`/`AfterFileDeletedEvent`, automatische Bereinigung alter Versionen und verwaister Dateien. Bis dahin bleiben alle Versionen erhalten; im Notfall wird komplett neu verarbeitet (siehe §4).

CLI:

```bash
vendor/bin/typo3 adaptive-video:scan --dry-run
vendor/bin/typo3 adaptive-video:scan
vendor/bin/typo3 adaptive-video:status [-v]
vendor/bin/typo3 adaptive-video:status <fileUid>
```

- `scan`: findet Dateien ohne passende Version, legt `pending`-Versionen an und versendet Messages; `--dry-run` zeigt nur an.
- `status`: Übersicht für den Betrieb – Schalter `processingEnabled`/`deliveryEnabled`, Anzahl Versionen je Status, laufende Versionen mit Laufzeit (Hinweis bei Überschreitung von `maxRuntime`) und fehlgeschlagene mit Fehlermeldung; `-v` listet zusätzlich übersprungene.
- `status <fileUid>`: zeigt alle Versionen einer Datei mit Status, aktiver Version, Fingerprint-Abgleich (Inhalt, Profil), Playlist-URL bzw. Fehlermeldung.

Das Rendering prüft die Existenz der Artefakte bewusst **nicht**; für konsistente Umgebungen (DB und Artefakte gemeinsam synchronisiert) ist das Projekt verantwortlich.

**Verarbeitung pro Umgebung:** Globaler Schalter `processingEnabled` (z. B. per ENV/`additional.php`). Ist er deaktiviert, werden keine `ProcessVideoMessage`s versendet und keine Versionen angelegt; vorhandene aktive Versionen werden weiterhin ausgeliefert. Gedacht für lokale, Review- und andere Umgebungen ohne ffmpeg bzw. ohne Bedarf an eigener Verarbeitung.

**Auslieferung pro Umgebung:** Globaler Schalter `deliveryEnabled` (Standard an), zusätzlich zur Aktivierung per Site Set. Ist er deaktiviert, übernimmt der Renderer keine Videos; es werden wie bisher die Originale ausgeliefert. Damit lassen sich Videos auf einer Umgebung zuerst kodieren und das Frontend erst nach einer Abnahme umschalten (z. B. Live kodiert, Review prüft mit den synchronisierten Artefakten). Nach dem Einschalten muss der Seiten-Cache geleert werden, da die Cache-Tags erst beim Rendern durch die Extension gesetzt werden.

### Rendering

- Bestehende TYPO3-FAL-Medienreferenzen und Content Elements weiterverwenden.
- Die Auswahllogik (aktive Version ermitteln, HLS oder Original) liegt in einem eigenen Service; der Renderer ist nur eine dünne Hülle darum, damit ein späterer ViewHelper dieselbe Logik nutzen kann.
- Das MVP deckt ausschließlich Ausgaben über `<f:media>` bzw. die `RendererRegistry` ab. Templates mit eigenem `<video>`-Markup werden erst über den späteren ViewHelper unterstützt.
- Ist eine passende aktive Version vorhanden **und** stimmt ihr `source_fingerprint` mit dem aktuellen `sys_file.sha1` überein, HLS mit HTML5-`<video>` ausgeben. Andernfalls Original ausgeben. Dieser Abgleich fängt auch Dateiänderungen ab, die kein Event auslösen (z. B. Überschreiben per FTP und Neuindexierung).
- Native HLS-Wiedergabe (z. B. Safari) bevorzugen; sonst hls.js bei unterstützter Browser-API.
- Ist kein Stream verfügbar oder schlägt die Initialisierung fehl, nach Möglichkeit Originalvideo verwenden.
- Bestehende TYPO3-/FAL-Einstellungen für Controls, Autoplay, Muted und Loop respektieren.
- **Poster:** Vorrang hat `additionalAttributes.poster` aus dem Template, sonst `sys_file_metadata.poster` aus `b13/poster-image` (Link-Wert `t3://file?uid=…` über `LinkService` bzw. `FileInterface` auflösen), sonst kein Poster. Gilt für HLS- und Original-Ausgabe gleichermaßen.
- **Renderer-Priorität:** Standard 20, konfigurierbar; liegt über `VideoTagWithPosterRenderer` von b13 (10) und dem Core-`VideoTagRenderer` (1). `canRender()` greift für unterstützte Video-Dateitypen, wenn die Extension für die aktuelle Site aktiviert ist; sonst übernehmen b13 bzw. Core wie bisher. Da der b13-Renderer damit verdrängt wird, übernimmt unser Renderer dessen Poster-Logik; die Original-Ausgabe delegiert an den Core-`VideoTagRenderer` und ergänzt das Poster.
- Kein eigener UI-Player und keine manuelle Qualitätsauswahl im MVP.
- hls.js nur dort laden, wo erforderlich.
- **Umsetzung (Phase 4):** Ausgabe als `<video data-adaptive-video-src="…/master.m3u8">` mit dem Original als `<source>` (Fallback ohne JavaScript und bei Fehlern). `autoplay` und `preload` setzt erst das Script, sonst lädt der Browser zuerst das Original; Autoplay-Videos werden `muted` ausgegeben. Ein kleines ES-Modul lädt hls.js (Light-Build, ESM) per `import()` nur ohne native HLS-Unterstützung. Segmente lädt hls.js sofort nur bei `preload=auto` oder Autoplay, sonst erst beim Abspielen.
- **Site Set `mfd/adaptive-video`:** Aktivierung erfolgt durch Einbinden des Sets in die Site. Settings: `adaptiveVideo.enabled` (Standard an), `adaptiveVideo.preload` (Standard `metadata`, greift nur ohne Angabe im Template), `adaptiveVideo.capLevelToPlayerSize` (Standard an).

**Zu prüfen:** Renderer-Registrierung in v14; Verhalten von `b13/poster-image` 2.x (Feldformat, Renderer-Priorität) gegen 1.x.

## 9. Konfiguration

**Global:** `processingEnabled`, `deliveryEnabled`, Provider-Auswahl, ffmpeg-/ffprobe-Pfade, Befehlspräfix (Standard `nice -n 19`), zusätzliche ffmpeg-Optionen (Standard `-threads 1`), Laufzeit- und Größenlimits, temporäres Arbeitsverzeichnis, Zielverzeichnis der Artefakte (Standard `_videos`), öffentliches URL-Präfix (relativ, Standard `/_videos/`, ohne Host – damit gilt bei mehreren Domains automatisch der jeweilige Host), verfügbare Encoding-Profile, unterstützte Dateitypen, Mindestdauer (Standard 10 s), technische Limits.

**Global zusätzlich:** Identifier des Standardprofils (MVP: genau ein aktives Profil).

**Site Settings:** Aktivierung und Player-Einstellungen (`preload`, `controls` etc.); **keine Profilwahl**. Keine zusätzlichen redaktionellen CE-Felder.

Bei der Anlage einer Version wird die **effektive Profilkonfiguration als Snapshot** gespeichert und gehasht. Dieselbe Quelldatei nutzt siteübergreifend dieselben Artefakte. Ändert sich das Standardprofil, unterscheidet sich der Fingerprint, und beim nächsten Scan wird eine neue Version erzeugt; die bisherige bleibt aktiv, bis die neue `ready` ist (ADR-010).

**Bewusst nicht im MVP:** Profile pro Site. Eine `sys_file_reference` ist bei der Hintergrund-Erkennung nicht eindeutig einer Site zuzuordnen (Ordner außerhalb von Sites, `pid=0`, Shortcuts/Referenz-CEs); unterschiedliche Profile bei Erkennung und Rendering würden dauerhaft zum Original-Fallback führen. Das Thema steht als Issue auf der Roadmap.

## 10. Administration, Sicherheit und Betrieb

- Kein umfangreiches Backend-Modul im MVP; möglichst kleine FAL-Statusanzeige und CLI-Befehle.
- TYPO3 Logging Framework mit Kontext aus Datei- und Versions-UID. Der Worker gibt die Einträge der Verarbeitung zusätzlich auf der Konsole aus (Start, Encoding und Freigabe ab `-vv`).
- Symfony Process mit Argumentlisten, niemals unkontrollierte Shell-Kommandoverkettung.
- Nur vertrauenswürdige FAL-Pfade und validierte Ausgabepräfixe; keine ungeprüften Benutzerargumente für ffmpeg.
- Limits für Laufzeit, Quellgröße und temporären Speicherplatz; Parallelität über die Anzahl der Worker (Infrastruktur-Konfiguration vor eigener Steuerungslogik).

### Worker-Betrieb

Die Extension dokumentiert zwei Betriebsarten; in beiden läuft **genau ein Worker**.

**Empfohlen: Supervisor (oder systemd).** Dauerhafter Prozess, automatischer Neustart. Der Core beendet den Worker nach 1 h (nach Abschluss der laufenden Nachricht); Supervisor startet ihn neu, so wird regelmäßig neuer Code geladen.

```ini
[program:adaptive_video_worker]
command=/path/to/vendor/bin/typo3 messenger:consume adaptive_video
autorestart=true
numprocs=1
stopwaitsecs=<maxRuntime>
```

**Einfache Alternative: Cron („poor man").** Für Umgebungen ohne Prozessverwaltung. `flock` verhindert parallele Worker; der Worker läuft bis zum Zeitlimit (bzw. bis zum Ende des laufenden Encodings) und wird vom nächsten Cron-Lauf neu gestartet. Solange er läuft, scheitern die minütlichen Aufrufe sofort am Lock.

```cron
* * * * * flock -n /tmp/adaptive_video.lock /path/to/vendor/bin/typo3 messenger:consume adaptive_video
```

Nach einem Deployment muss der Worker neu gestartet werden (Supervisor: `supervisorctl restart`, Cron: spätestens nach dem Core-Zeitlimit). Ein harter Abbruch während eines Encodings wird über Redelivery und Timeout-Übernahme aufgefangen (§6).
- Bereinigung temporärer Arbeitsverzeichnisse nach jedem Lauf (auch bei Fehlern). Keine automatische Bereinigung alter Versionen im MVP.
- Cache-Invalidierung unabhängig vom Encoding über `CacheInvalidator`; reguläre TTL als Fallback.
- CDN-/Webserver-Konfiguration muss passende MIME-Typen, CORS (falls erforderlich), Cache-Header und sichere öffentliche Pfade gewährleisten.

### Content Security Policy

Die Streams werden vom selben Host wie die Website ausgeliefert; für Playlists und Segmente genügt daher `'self'`. hls.js benötigt jedoch zusätzlich `blob:` (MediaSource-URL am `<video>` sowie Transmuxing im Web Worker, `enableWorker` bleibt aktiv). Mindestanforderung:

```text
media-src   'self' blob:;
worker-src  'self' blob:;
connect-src 'self' …;   (bereits vorhanden)
```

Die Extension setzt keine CSP; die Anforderungen werden in der Extension-Dokumentation beschrieben. Ohne `blob:` scheitert die Wiedergabe in allen Browsern ohne native HLS-Unterstützung (Chrome, Firefox, Edge). Liegt das URL-Präfix auf einem anderen Host als die Website, muss dieser zusätzlich in `media-src` und `connect-src` erlaubt werden.

## 11. Tests und Implementierungsphasen

**Unit:** Profilinterpretation, Fingerprints, Rendition-Auswahl, Hochformat, kein Upscaling, Mindestdauer, Statusübergänge, Aufbau der ffmpeg-Argumentliste.  
**Functional (TYPO3):** Datenbankschema und Unique-Indizes, Reservierung und Timeout-Übernahme, Auswahl der aktiven Version (Fingerprint-Abgleich, neueste `ready`), FAL-Referenzen, `AfterFileReplacedEvent`, Site-Aktivierung, Messenger-Dispatch, Idempotenz, Cache-Tags. Gegen **MariaDB/MySQL**, nicht nur SQLite, da die Reservierung auf `UniqueConstraintViolationException` und `affectedRows` beruht.  
**Integration:** echtes ffprobe/ffmpeg, 4K/720p/Hochformat/ohne Audio/mit Audio/unter Mindestdauer/defekte Dateien, CMAF-Segmente, Playlist-Validierung.

**Testvideos:** werden zur Testlaufzeit per ffmpeg erzeugt (`-f lavfi -i testsrc2=…`, `sine` für Audio); keine Binär-Fixtures im Repository außer einer kleinen, absichtlich defekten Datei.

**Nicht automatisiert:** tatsächliche Wiedergabe im Browser (hls.js, natives HLS in Safari), Original-Fallback im Browser, CDN/Proxy-Caching. Dafür eine manuelle Abnahme-Checkliste in der Dokumentation.

**Ort und Zeitpunkt:**

- Entwicklung zunächst im Projekt unter `extensions/adaptive_video`; Auslagerung in ein eigenes, öffentliches Repository (GitHub) später bzw. sobald sinnvoll.
- Unit- und Functional-Tests laufen bis dahin in der GitLab-CI des Projekts (Job nur bei Änderungen unter `extensions/adaptive_video/**`). PHPStan/PHPCS des Projekts decken die Extension bereits ab.
- Integrationstests mit echtem ffmpeg und die Versionsmatrix (TYPO3 v13/v14 × unterstützte PHP-Versionen) werden erst nach der Auslagerung in der GitHub-CI eingerichtet.
- Tests werden **in der Phase der jeweiligen Funktion** mitgeliefert, nicht gesammelt am Ende.

Phasen:

1. Extension-Grundgerüst, Datenbankschema (`ext_tables.sql`), Konfiguration und Datenmodell; Testinfrastruktur (PHPUnit-Konfiguration, CI-Job) und Functional-Tests für Reservierung/Unique-Indizes.
2. FAL-Erkennung per Scan (`adaptive-video:scan`, `VersionScheduler`), Messenger-Transport, Message und Handler, Quellanalyse per ffprobe mit Skip-Regeln (Mindestdauer, Größen-/Dauerlimits); zugehörige Unit-/Functional-Tests.
3. ffmpeg-Encoding und -Packaging, Validierung, lokaler Artefakt-Storage und Freigabe; zugehörige Unit-/Functional-Tests. Mit echtem ffmpeg manuell geprüft (Quer-/Hochformat, gedreht, ohne Audio, 60 fps, Quelle unter 360p).
4. TYPO3-Renderer, Site Settings, hls.js, Fallback und Cache-Invalidierung inkl. `AfterFileReplacedEvent`; zugehörige Functional-Tests.
5. CLI `status`, DataHandler-Hook für sofortiges Einreihen, Logging (Worker-Ausgabe ab `-vv`), Betriebsdokumentation (`README.md`) und manuelle Abnahme-Checkliste (`Documentation/AcceptanceChecklist.md`).
6. Nach Auslagerung: Integrationstests mit ffmpeg und Versionsmatrix in der GitHub-CI.

## 12. Offene Implementierungsprüfungen

Diese Punkte sind **nicht** als bereits verifizierte TYPO3-APIs zu verstehen:

1. ~~TCA-basierte Schema-Generierung~~ – entschieden (ADR-016): `ext_tables.sql` mit Unique-Indizes.
2. ~~`AfterFileReplacedEvent` in v13/v14 prüfen~~ – geprüft (v13.4, v14.3: identische Signatur). DataHandler-Hook umgesetzt (Phase 5), weitere FAL-Events erst nach dem MVP. Site-Zuordnung entfällt (ADR-014), Erkennung ist durch den Scan abgesichert (ADR-008).
3. ~~FAL-Driver-Operationen ohne `sys_file`-Registrierung~~ – entfällt (ADR-015): Artefakte werden außerhalb von FAL abgelegt.
4. Renderer-Priorität entschieden (20, über b13 und Core). Registrierung geprüft: v13 und v14.3 per `RendererRegistry::registerRendererClass()` in `ext_localconf.php`; erst v15 stellt auf `#[AsFileRenderer]` um (dort ist `registerRendererClass()` ein No-op).
5. ~~FAL-Poster-Metadaten~~ – entschieden (ADR-006): Feld `poster` aus `b13/poster-image`.
6. Messenger: Verhalten in v13.4 geprüft (kein Retry, `redeliver_timeout` 3600 s im Core-Transport, eigener Transport vorgesehen); Verhalten in v14 noch zu verifizieren. Consumer-Betrieb auf dem Zielhost offen.
7. Idempotenz bei parallelen Workern: entschieden (Unique-Index, bedingte Updates, Freigabe über einzelnes Status-Update); in Functional-Tests absichern.
8. Gemeinsame CMAF-Segmente für HLS und zukünftiges DASH unter realen Playern.
9. Cache-Tags: Konzept entschieden (Tag pro Datei, Flush nach Freigabe und beim Ersetzen einer Datei). Request-Zugriff geprüft: `$GLOBALS['TYPO3_REQUEST']` trägt in v13/v14 `frontend.cache.collector`. Das Core-`AddCacheTagEvent` wäre eine Alternative, ist aber `@internal`.
10. ~~Multisite-Profilidentität~~ – entfällt im MVP (ADR-014): ein globales Standardprofil.

## 13. Roadmap außerhalb des MVP

- Listener für `AfterFileContentsSetEvent` und `AfterFileDeletedEvent`.
- Automatische Bereinigung alter Versionen (Aufbewahrungsfrist) und von Versionen zu Dateien ohne aktive Referenz.
- CLI: `adaptive-video:process <fileUid>` (gezielte Neuverarbeitung) und `scan --repair` (Versionen mit fehlenden Artefakten zurücksetzen).
- Automatisch erzeugtes Poster (ffmpeg-Frame als Artefakt), wenn kein Poster gepflegt ist.
- Asynchrone Cache-Invalidierung über eine eigene `InvalidateCacheMessage` (Issue), falls der direkte Aufruf im Worker nicht ausreicht.
- Encoding-Profile pro Site bzw. pro Datei (Issue). Voraussetzung: eindeutige Site-Auflösung bei der Erkennung oder Dispatch aus dem Rendering bei fehlender passender Version.
- Fluid-ViewHelper für adaptive Videos (z. B. `<av:video file="{file}" />`) für Templates mit eigenem `<video>`-Markup.
- AWS MediaConvert-Provider mit asynchroner Fertigmeldung, zusammen mit einem S3-`ArtifactStorage` (Ausgabe direkt in den Bucket).
- MPEG-DASH-Manifeste und Prüfung gemeinsamer CMAF-Segmente.
- Austauschbare Player und erweiterte Bedienoberfläche.
- Manuelle Qualitätswahl.
- Mehrsprachige Audiospuren und Untertitel.
- Umfangreicheres Backend-Monitoring und Batch-Verwaltung.


---

**Referenz:** TYPO3 Core API – Message Bus: <https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/MessageBus/Index.html>
