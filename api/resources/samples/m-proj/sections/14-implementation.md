# Chapter 5: Implementation

Chapter 4 described what Dose should look like and how it should behave, and traced each design decision to the requirements of Chapter 3. This chapter describes how the working prototype of iteration 3 was built. It completes the answer to RQ2 by showing that the design could be realized within the technical constraints of the setting, in particular offline operation (R1), conflict-safe synchronization (R4), a low-end device and small package (R7), and on-device security (R9). In the Design Science Research Methodology, implementation belongs to the design and development activity and prepares the artefact for demonstration and evaluation {{peffers2007}}; in {{hevner2004|n}}'s terms, it is where the artefact is shown to be technically feasible before its utility is assessed.

Section 5.1 explains the choice of platform. Section 5.2 gives an overview of the architecture, and Sections 5.3 to 5.5 describe the three parts that carried most of the technical risk: local storage, synchronization, and encryption. Section 5.6 covers the remaining features, and Section 5.7 reports how the prototype was tested. Section 5.8 summarizes the chapter.

## 5.1 Platform choice

The first decision was whether to build a native Android application or a web application. A native app offered more control over storage and background work; a web app was quicker to change and could reuse the clickable prototype's layouts. I chose a middle path: a progressive web app (PWA) packaged as an Android application package (APK) using a thin wrapper that loads the app from local files rather than from the internet.

Three considerations decided the matter. First, the CHWs installed apps through APK files shared by their supervisors, not through an app store, and a packaged app fitted that practice. Second, packaging the app meant that nothing needed to be downloaded on first use, which mattered where data bundles were shared and costly, a constraint long noted in work on technology for developing regions {{brewer2005}}. Third, the wrapper kept the package small. The final APK was 11.8 MB, within the 15 MB limit of R7.

## 5.2 Architecture overview

Dose follows a local-first architecture: the phone holds the primary, fully usable copy of the CHW's data, and the server is a place where copies meet rather than the place where the data lives {{kleppmann2019}}. {{kleppmann2019|n}} set out ideals for such software, including fast response without network round-trips, full use while offline, and synchronization across devices without loss. Dose adopts the first two in full and the third in a deliberately simplified form (Section 5.4).

> **Figure 5.1** Dose architecture. On the phone: user interface layer → domain layer (schedules, visits, due-date rules) → encrypted local store (IndexedDB) ↔ sync engine. Across the network (when available): sync engine ↔ REST API on the district server → relational database → weekly supervisor summary and register export. Dashed line between phone and server indicates intermittent connection.

The client is organized into four layers. The user interface layer renders the screens described in Chapter 4 and never calls the network directly. The domain layer holds the rules of follow-up: how due dates are calculated from each patient's condition and last visit, which group a patient belongs to, and how visits are validated. The storage layer encrypts and decrypts records on their way into and out of the browser's IndexedDB database. The sync engine runs in the background, exchanging changes with the server when a connection appears.

The server is a small web service with a relational database. It exposes a REST-style interface: patients, schedules, and visits are resources identified by URLs and exchanged as JSON documents over HTTPS using standard methods. The server merges changes from devices, sends each device the changes it has not seen, and produces the supervisor summary (R8) and register export (R11). Records arrive encrypted (Section 5.5), so the server stores them as encrypted payloads alongside the few unencrypted fields needed for scheduling and reporting.

## 5.3 Local storage and data model

Data on the phone are kept in IndexedDB, the structured storage available to web applications. Table 5.1 summarizes the data model. Two features of it are central to the rest of the chapter. Patient and schedule records are mutable: their fields change over time, for example when a follow-up is rescheduled. Visits, by contrast, are stored in an append-only log: a visit, once saved, is never edited or deleted. Corrections made after the 24-hour correction window described in Chapter 4 are recorded as new log entries that refer to the visit they correct.

**Table 5.1** Main record types in the Dose data model

| Record | Mutable? | Key fields | Merge rule |
|---|---|---|---|
| Patient | Yes | name, village, landmark, condition, language preference | Last writer wins, per field |
| Schedule | Yes | patient, next due date, interval, status | Last writer wins, per field |
| Visit | No (append-only) | patient, date, outcome, medication status, note, CHW, device | Union of logs; no conflicts possible |
| Correction | No (append-only) | visit referred to, changed fields, reason | Union of logs; applied in time order |

*Note.* Each mutable field carries a timestamp and device identifier used in merging (Section 5.4).

The append-only design for visits had two motivations. The first was auditability: supervisors had stressed in the requirements interviews that the paper register is a legal record, and that crossings-out must remain visible (Chapter 3). An append-only log preserves the same property. The second was technical: if visits are never edited, two devices can never produce conflicting versions of the same visit, and merging visit logs reduces to taking the union of their entries.

The home list is computed from the schedule records each time the app opens or a visit is saved. Each schedule record stores its next due date, so grouping patients into overdue, due today, and upcoming needs only a single pass over the schedules and a date comparison, which kept the list fast on the test device (Section 5.7.3).

## 5.4 Synchronization

### 5.4.1 When sync happens

The sync engine runs whenever the app detects a connection, whenever the app is opened, and after each saved visit. If an attempt fails, it waits before retrying, doubling the wait each time from 30 seconds up to a maximum of 30 minutes, with a small random variation so that phones returning to coverage together do not all retry at once. This exponential back-off keeps the app from repeatedly waking the radio in poor coverage, which helps with battery use (R12). Sync uses only mobile data or Wi-Fi; Dose never sends SMS messages, so the CHW bears no per-message cost (R10). Each exchange carries only the changes since the last successful sync, compressed, so that a typical day's visits fit in a few kilobytes.

### 5.4.2 Merging changes

Each change to a mutable field records the time it was made and the identifier of the device that made it. When two versions of the same field meet, during sync on the server or when the server's changes arrive on a phone, the version with the later timestamp wins, and the device identifier breaks exact ties. Merging is done field by field, not record by record, so that two people changing different fields of the same patient do not overwrite each other. This is a simple form of the conflict-free replicated data types discussed by {{kleppmann2019|n}}: because the merge rule is deterministic and does not depend on the order in which changes arrive, all copies converge to the same state once they have exchanged changes.

```ts
// Merge two versions of a record, field by field (last writer wins).
function mergeRecord(local: Rec, remote: Rec): Rec {
  const out: Rec = { id: local.id, fields: {} };
  for (const name of union(keys(local.fields), keys(remote.fields))) {
    const a = local.fields[name], b = remote.fields[name];
    out.fields[name] = !a ? b : !b ? a : newer(a, b);
  }
  return out;
}
// newer(): compare timestamps, then device IDs to break ties.
```

Phone clocks cannot be fully trusted. To limit the effect of a phone with a wrong clock, each device records the server time at its last sync and adjusts its timestamps by the observed offset. Timestamps more than a day ahead of server time are flagged for supervisor review rather than accepted silently. This mitigation is not complete, and I return to it in Section 5.8.

### 5.4.3 What the user sees

The design principle of quiet sync (Chapter 4) meant that merging should happen without asking the CHW to decide anything. In the cases tested, automatic resolution produced the expected result. However, last writer wins can hide a genuine disagreement: if a CHW and a supervisor both reschedule the same patient while offline, one of the two dates is kept and the other is discarded without notice. I recorded overwritten values in a server-side history so that they could be recovered, but the prototype does not show them to users. A conflict view that would let a supervisor see and resolve such cases was designed but not built in time for the evaluation. For this reason Chapter 6 assesses R4 as only partially met.

## 5.5 Security and privacy

R9 requires a PIN lock and encryption of patient data at rest. Shared and borrowed phones were common in Lowmoor (Chapter 3), so a lost or lent phone had to reveal nothing useful.

Dose uses the browser's built-in Web Crypto interface rather than a third-party library. When the CHW sets a PIN, a key is derived from it using PBKDF2 with a random salt and a high iteration count. That key is used to unwrap a randomly generated data key, and the data key encrypts each record with AES-GCM, which provides both confidentiality and a check that the record has not been altered. Neither the PIN nor the derived key is stored; the data key is held in memory only while the app is unlocked and is discarded when the app is locked or closed.

```ts
// Derive a key-encryption key from the CHW's PIN.
const base = await crypto.subtle.importKey(
  "raw", encode(pin), "PBKDF2", false, ["deriveKey"]);
const kek = await crypto.subtle.deriveKey(
  { name: "PBKDF2", salt, iterations: ITERATIONS, hash: "SHA-256" },
  base, { name: "AES-GCM", length: 256 }, false, ["unwrapKey"]);
```

A four-digit PIN has few possible values, so the derivation alone does not protect against an attacker with the phone and time. Two measures address this. After five wrong attempts the app imposes a growing delay, and the data key is also wrapped by a recovery key held by the district server, so that a supervisor can reset a forgotten PIN after the phone next syncs. This design trades some security for practicality; a phone that is both stolen and never connected again remains protected only by the PIN and the delay. I judged this acceptable for a prototype but note it as a limitation [source needed].

## 5.6 Other features

**Language.** All interface text is held in two string files, English and Kiswahili, loaded at start-up; the header toggle (Chapter 4) switches between them instantly without reloading. Kiswahili strings were translated by a district health promoter and checked by two of the requirements CHWs. Labels were designed for English lengths, and longer Kiswahili strings were not checked on every screen, a gap the evaluation later exposed (Chapter 6).

**Accessibility.** Text uses a minimum size of 16 sp and scales with the phone's font setting. Status colors were checked against the contrast criteria of WCAG 2.1 {{w3c2018}}, and every color is paired with an icon and word, as described in Chapter 4. Touch targets are at least 48 × 48 dp.

**Supervisor summary.** The weekly summary (R8) is computed on the server each Monday from the visits received and shown to supervisors on a single screen: visits completed, patients overdue by more than seven days, and patients marked as moved or referred. It shows district totals only.

**Register export.** For audit (R11), the server produces a printable file laid out like the paper register, one page per CHW per month, listing every visit and correction in time order.

**Location.** Dose makes no use of GPS or location services (R12). Households are identified by village and landmark text, as on the paper register.

## 5.7 Testing

Testing at this stage asked whether the artefact worked as specified, not whether it was usable or useful; those questions belong to Chapter 6. Three kinds of testing were carried out.

### 5.7.1 Unit tests

The domain, storage, and sync code was covered by 64 automated unit tests, summarized in Table 5.2. All 64 passed on the build used for evaluation. The largest groups covered due-date calculation, where errors would directly undermine R2, and record merging.

**Table 5.2** Automated unit tests by area (n = 64)

| Area | Tests | Examples of what is checked |
|---|---|---|
| Due-date and grouping rules | 14 | Interval by condition; overdue on the day after due; month and year boundaries |
| Record merging | 18 | Field-level last writer wins; tie-breaking; visit log union; corrections applied in order |
| Encryption and PIN | 9 | Round-trip encrypt/decrypt; wrong PIN rejected; tampered record detected; lockout delay |
| Visit form validation | 10 | Defaults; no future visit dates; next date after visit date |
| Export and summary | 6 | Register layout; corrections shown; weekly totals |
| Language strings | 7 | Every English key has a Kiswahili value; no missing placeholders |
| **Total** | **64** | |

### 5.7.2 Sync conflict tests

Because unit tests do not show that devices converge in practice, I wrote scripted scenarios using two simulated phones and a test server, in which network connections were switched off and on in controlled sequences. Scenarios included both phones editing the same field offline, editing different fields of the same patient, recording visits for the same patient on both phones, a phone with its clock set a day ahead, and a sync interrupted midway. After each scenario, both phones and the server were compared record by record. In all scenarios the three copies converged to the same state, no visit was lost, and the interrupted sync resumed correctly on the next attempt. As Section 5.4.3 noted, convergence does not mean that the outcome was what both users intended.

### 5.7.3 Performance on a low-end device

R7 requires Dose to work on an Android phone with 1 GB of RAM. I tested on such a device, of a model sold in the district, with 300 synthetic patient records, a deliberately generous caseload for a single CHW. Table 5.3 reports the results, each the median of ten runs.

**Table 5.3** Performance on a 1 GB RAM test device (300 patients)

| Measure | Result | Requirement |
|---|---|---|
| APK size | 11.8 MB | R7: < 15 MB |
| Cold start to usable home list (including PIN entry screen) | 2.9 s | R7 |
| Render home list of 300 patients after unlock | 410 ms | R2, R7 |
| Save visit to encrypted local store | under 100 ms | R1, R3 |

*Note.* Median of ten runs. The phone was restarted before each cold-start measurement.

A cold start of 2.9 seconds is noticeable, but it occurs only when the app is first opened; returning to it from the background is near-instant. The 410 ms render of the full list met the informal goal I had set of under half a second. Earlier builds that rendered visit histories in the list took several times longer, which led to the change described in Section 4.4. Battery use was not measured in field conditions; the only evidence for R12 is the design choice of no GPS and back-off sync, together with informal lab observation, so Chapter 6 treats R12 as not evaluated.

## 5.8 Summary and limitations

This chapter has described how Dose was built as a local-first progressive web app packaged for Android. A layered client keeps all user actions on the device; an encrypted IndexedDB store protects patient data at rest; a sync engine with exponential back-off exchanges changes whenever a connection appears; and a field-level last-writer-wins rule, combined with an append-only visit log, makes copies converge without user intervention. The prototype passed its 64 unit tests and its sync conflict scenarios and met the size and speed targets of R7 on a 1 GB device. Together with Chapter 4, this answers RQ2: the requirements identified in Chapter 3 can be embodied in an offline-first design that is technically feasible on the CHWs' phones, in line with earlier design lessons for health worker tools in low-resource settings {{derenzi2011}}.

The implementation also has specific limits. Last writer wins resolves conflicts silently, and the conflict view needed to make that safe was not built. Clock correction relies on regular sync and does not cover a phone that rarely connects. The PIN-based encryption protects against casual access but not a determined attacker with time. Performance was measured on one device model, and battery use was not measured at all. Whether the artefact is usable and useful to CHWs is a separate question, which Chapter 6 addresses through a task-based usability study with twelve CHWs.
