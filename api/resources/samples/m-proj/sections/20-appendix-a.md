# Appendix A: Requirements Specification

This appendix gives the full specification of the twelve requirements derived in Chapter 3. Each requirement is stated with its MoSCoW priority, the evidence from the contextual inquiry that motivated it, and the acceptance criterion used to judge it in the traceability analysis of Chapter 6 (Table 6.5). Priorities follow Table 3.2: "Must" requirements define the minimum viable tool, "Should" requirements could be deferred without making the tool unusable, and none was classed as "Could" or "Won't".

**Table A.1** Requirements R1–R12 with priority, source and acceptance criterion

| ID | Requirement | Priority | Source (Chapter 3) | Acceptance criterion |
|---|---|---|---|---|
| R1 | The tool works fully offline: every CHW task can be completed without a network connection. | Must | Intermittent coverage observed on all six shadowing days; shared data bundles | All CHW tasks completed in airplane mode with no data loss |
| R2 | On opening, the tool shows a list of patients due today and overdue. | Must | CHWs reconstructed due dates from memory; missed follow-ups unnoticed | Overdue and due-today patients visible on the first screen without navigation |
| R3 | A routine visit can be recorded in no more than three taps after selecting the patient. | Must | Short doorstep visits; register completed later from memory | Three taps or fewer for a visit with no changes to defaults |
| R4 | Records synchronize automatically when any connection appears, without losing or silently overwriting data. | Must | Patients shared between CHWs during cover; supervisors' concern about double entries | Automatic sync on reconnection; conflict tests pass; CHW can see that records arrived and how conflicts were resolved |
| R5 | Interface uses icons with short text, with an English/Kiswahili language toggle. | Should | Mixed reading preferences; CHWs switching language with families | All core screens available in both languages; toggle reachable from the home screen |
| R6 | Touch targets are large and text contrast is high, following WCAG 2.1 guidance. | Must | Outdoor use in bright light; older CHWs with reading glasses | Core controls meet WCAG 2.1 contrast and target-size criteria {{w3c2018}} |
| R7 | The tool runs on Android phones with 1 GB RAM, with an installation package under 15 MB. | Must | Low-end personal and shared phones; APKs shared by supervisors | APK < 15 MB; usable home list on a 1 GB test device within a few seconds |
| R8 | Supervisors receive a weekly summary of follow-up activity. | Should | Supervisors' monthly register reviews too late to act on | Supervisor can answer routine weekly questions from the summary alone |
| R9 | The tool is locked by a PIN and patient data are encrypted at rest. | Must | Shared and borrowed phones; confidentiality of TB status | PIN required on open; stored records unreadable without the key |
| R10 | The tool imposes no SMS cost on the CHW. | Must | CHWs paying for work messages from personal credit | No SMS sent by the tool in any workflow |
| R11 | Visit records can be exported in a layout matching the paper register for audit. | Should | District audit practice built around the register | Printable export per CHW per month, including corrections |
| R12 | The tool is battery-light and does not use constant GPS. | Should | Limited charging opportunities during field days | No continuous location services; phone lasts a working day in field use |

*Note.* Acceptance criteria were fixed before the evaluation.

## A.1 Requirements outside scope

Three candidate features were excluded from this version: a map view of households, which conflicted with R7 and R12 (Chapter 4); integration with the district health information system, which lay outside the scope of a master's project (Chapter 7); and SMS reminders to patients, which conflicted with R10 and would have made patients participants.

## A.2 Status at the end of the project

Nine requirements were met (R1, R2, R3, R5, R6, R7, R9, R10, R11), two were partially met (R4, because the conflict view was designed but not built and delivery was not confirmed to the user; R8, because the summary shows district totals only and supervisors asked for a per-CHW breakdown), and one was not evaluated (R12, because no battery measurements were taken). The evidence for each status is given in Table 6.5.
