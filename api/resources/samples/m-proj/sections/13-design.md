# Chapter 4: Design

Chapter 3 ended with twelve requirements, R1 to R12, grounded in six days of contextual inquiry with community health workers in Lowmoor District and interviews with their two supervisors. This chapter answers the second research question: how can those requirements be embodied in an offline-first mobile design? In the vocabulary of the Design Science Research Methodology, it covers the "design and development" activity, and it reports how the design was demonstrated to users at each turn {{peffers2007}}. Within the framework set out in Chapter 2, this is the design cycle, the iterative loop of building and evaluating that sits between the relevance cycle (the field and its requirements) and the rigor cycle (the knowledge base) {{hevner2004}}.

The chapter is organized around three iterations. Section 4.1 explains the approach and the principles that guided it. Sections 4.2 to 4.4 report each iteration in turn: paper prototypes, a clickable prototype, and a working prototype. Section 4.5 brings together the key design decisions as rationale tables that trace each decision to a requirement and to the evidence behind it. Section 4.6 reflects on the process.

## 4.1 Design approach

### 4.1.1 Research through design

I treated the design work as research through design: the artefact is not just a product of the study but the means by which knowledge about the problem is produced {{zimmerman2007}}. {{zimmerman2007|n}} argue that such work should be judged on the quality of its process, the degree of invention it represents, its relevance to a real situation, and whether others can extend it. Those criteria shaped how I documented each iteration: the version tested, who saw it, the problems found, and the changes that followed.

I also kept a design journal throughout. {{schon1983|n}} describes design as a reflective conversation with the materials of a situation, in which the designer makes a move, sees how the situation responds, and adjusts. Several decisions reported below began as such surprises in the journal.

### 4.1.2 Guiding principles

Two bodies of design guidance served as a shared vocabulary for the decisions. The first was {{norman2013|n}}'s account of signifiers, feedback, mapping and conceptual models, which proved most useful for explaining why a screen did or did not make sense to a CHW. The second was the set of eight "golden rules" of interface design, in particular consistency, informative feedback, error prevention, easy reversal of actions, and reducing short-term memory load {{shneiderman2016}}. Because some CHWs in Lowmoor read English slowly and preferred Kiswahili for detailed instructions, I also drew on work on text-free and low-text interfaces, which recommends pairing meaningful graphics with minimal text and avoiding reliance on reading for core tasks {{medhi2007}}. Finally, accessibility criteria for contrast and target size framed R6 in measurable terms {{w3c2018}}.

From these sources and the requirements I derived five working principles that I applied at every review:

1. **The list is the job.** Opening the app should show the work for today, not a menu (R2).
2. **Never block on the network.** No action should wait for, or fail because of, a connection (R1, R4).
3. **Count the taps.** Every core task has a tap budget, checked on each version (R3).
4. **Color is never alone.** Every status uses color, an icon, and a short word together (R5, R6).
5. **Quiet by default.** Background processes such as sync report their state without interrupting the CHW's work (R4, R12).

### 4.1.3 Overview of the iterations

Table 4.1 summarizes the three iterations. Participants in iterations 1 and 2 were drawn from the six CHWs who took part in the contextual inquiry (Chapter 3), because they already understood the purpose of the project and could compare the prototypes with their paper registers. The twelve CHWs in the evaluation reported in Chapter 6 were a different group, so that the final evaluation was not run with people who had shaped the design.

**Table 4.1** Summary of design iterations

| Iteration | Fidelity | Participants | Main evaluation activity | Main outcome |
|---|---|---|---|---|
| 1 | Paper prototypes (three home-screen concepts) | 4 CHWs (Grace, Joseph, Mercy, Ruth) | Task walkthroughs with paper screens | List-first home screen chosen; visit form cut to 4 fields |
| 2 | Clickable prototype on a low-end Android phone | 5 CHWs (Grace, Joseph, Mercy, Peter, Samuel) | Task walkthroughs and heuristic review | Sync redesigned as a badge; language toggle moved; 14 heuristic issues addressed |
| 3 | Working prototype (offline-first PWA) | Internal testing; supervisors for summary screen | Functional and performance testing (Chapter 5) | Version used in the evaluation (Chapter 6) |

*Note.* Names are pseudonyms. Ruth was unavailable for iteration 2; Peter and Samuel joined it.

## 4.2 Iteration 1: Paper prototypes

### 4.2.1 Three concepts

The first iteration explored the overall shape of the tool rather than its details. I sketched three competing concepts for the home screen, each a plausible reading of the requirements:

- **Concept A, "Register,"** reproduced the paper register on screen: a table of patients by household with columns for each scheduled visit.
- **Concept B, "Calendar,"** organized follow-ups by day and week, with patients appearing as entries on dates.
- **Concept C, "List,"** opened on a single list of patients needing attention, grouped into overdue, due today, and upcoming, each group marked by color, an icon, and a word.

Each concept had five to seven paper screens, drawn at the actual size of the CHWs' phones, covering three tasks: find who needs a visit today, record a visit, and change the next follow-up date.

### 4.2.2 Procedure

Sessions took place at the Lowmoor sub-district health post and lasted about forty minutes. I acted as the "computer," swapping paper screens as each CHW tapped, and asked them to think aloud. The order of concepts was rotated across the four participants. After the tasks, each CHW was asked which concept they would want to carry and why.

### 4.2.3 Findings

All four CHWs preferred Concept C. The reasons were consistent with what the contextual inquiry had shown: the register is a record, but the morning question is "who must I see?" Concept A required scanning wide tables; two participants turned the phone sideways and lost their place. Concept B separated patients by date in a way that hid the overdue ones; Mercy, after paging back through three days, said she would forget last week's people, which is exactly the failure Dose was meant to prevent. Concept C made overdue patients visible without any searching.

**Table 4.2** Main findings from iteration 1 (n = 4 CHWs)

| Finding | Observed in | Design response |
|---|---|---|
| Overdue patients hidden in the calendar view | 3 of 4 | Adopt list-first home screen (Concept C) |
| Wide register table hard to scan on a small screen | 4 of 4 | Drop register layout from the app; keep it for export (R11) |
| Original visit form (9 fields) too long; fields skipped | 4 of 4 | Reduce to 4 fields with defaults |
| Red/green distinction alone unclear in sunlight | 2 of 4 | Add icon and word to every status |
| Uncertainty whether a record was "sent" | 3 of 4 | Introduce explicit saved-on-phone confirmation |

The visit form was the second major finding. My first version had nine fields mirroring the register's columns, including blood pressure and weight. Every participant skipped fields, and Joseph pointed out that the register asks for these only at clinic visits, not at home follow-ups. The form was therefore cut to four fields (Section 4.5), which made the three-tap target of R3 achievable.

The last finding in Table 4.2 anticipated the most persistent design problem of the project. When asked what happened after they pressed "Save," three CHWs assumed that the data had gone to the clinic. On paper, that question never arises. The distinction between "saved on this phone" and "sent to the clinic" needed to be made visible, and it became the central concern of iteration 2.

## 4.3 Iteration 2: Clickable prototype and heuristic review

### 4.3.1 The prototype

The second iteration turned Concept C into a clickable prototype with 22 screens, built as linked web pages and loaded onto the same model of low-end Android phone used by the CHWs. It behaved like an app with scripted data, and included two alternative versions of sync feedback.

### 4.3.2 Heuristic review

Before testing with CHWs, I carried out a heuristic review of the prototype against {{nielsen1993|n}}'s usability heuristics, joined by a fellow student in the program who had not seen the design. Heuristic review is inexpensive and catches many surface problems before users encounter them {{nielsen1993}}. Each reviewer worked independently and the lists were then merged. We identified 19 distinct issues, of which 14 were rated as worth fixing before the user sessions. The most frequent categories were consistency, visibility of system status (no indication of whether data had been synced), and error prevention (a reschedule screen that allowed past dates).

### 4.3.3 Walkthroughs with CHWs

Five CHWs then completed the same three tasks as in iteration 1, plus two new ones: synchronizing after returning to coverage, and switching the interface to Kiswahili. Sessions lasted about forty-five minutes. Two findings changed the design substantially.

The first concerned sync feedback. Version 1 of the prototype showed a full-screen dialog, "Sending data… please wait," whenever a connection was found. Version 2 showed a small badge in the header with a count of unsent records. I alternated the order between participants. The dialog was disliked by all five: it appeared at unpredictable moments, interrupted visit recording, and two participants pressed "Cancel" believing they would otherwise use up the shared data bundle. Samuel called it "the app asking permission for something I did not do." The badge was not noticed by everyone, but when it was, it was understood as "there are things waiting." This was the origin of the "quiet sync" decision (Section 4.5.3).

The second concerned language. The toggle had been placed in a settings screen two levels deep. Three of the five participants could not find it. Peter commented that he would switch language when explaining something to a patient's family, which meant it had to be reachable quickly, not configured once. The toggle was moved to the header as a two-letter "EN / SW" control.

> I will show the patient's mother, and she reads Kiswahili. So I need to change it there, at the door. (Peter, iteration 2)

Smaller changes included larger targets on the patient list, a confirmation naming the patient on the visit form, and a date picker replacing a free-text date in the reschedule flow. The last of these was a reasonable choice at the time; as Chapter 6 reports, the picker itself later proved to be one of the three severe problems.

## 4.4 Iteration 3: Working prototype

The third iteration produced the working prototype used in the evaluation. Unlike the earlier versions, it stored real (synthetic) patient data on the device, worked with the network switched off, and synchronized with a server. Its architecture and testing are described in Chapter 5; here I note the design changes that occurred during building.

Three design changes came from implementation rather than from users. First, the patient list was capped at showing the most recent visit only, rather than a visit history, because rendering histories made the list slow on a 1 GB device (R7). The history moved to the patient detail screen. Second, I had planned a map view of patient households, but it would have required location services and map tiles, conflicting with both R12 (battery) and R7 (package size); it was dropped. Households are identified by the village and landmark text already used on the register. Third, the supervisor weekly summary (R8) was designed as a single screen of district-level counts. I showed a static mockup to the two supervisors, Mrs. Adongo and Mr. Kiprop, before building it. Both accepted the counts as useful; the request for a per-CHW breakdown came later, in the walkthrough reported in Chapter 6.

The final version needed three taps from patient selection to a saved visit when the defaults were accepted: "Record visit," the outcome, and "Save."

## 4.5 Key design decisions and rationale

This section presents the decisions that most shaped Dose. Each is traced to the requirements it serves and to the evidence behind it, following the expectation in design science that an artefact's form be justified by both the problem context and the knowledge base {{hevner2004}}. Table 4.3 gives the overview; the subsections explain the four most consequential decisions.

**Table 4.3** Design rationale: decision, requirement, and evidence

| Decision | Requirements | Evidence |
|---|---|---|
| List-first home screen with overdue, due today, upcoming groups | R2, R1 | Iteration 1 (4 of 4 preferred); contextual inquiry (Ch. 3); reducing memory load {{shneiderman2016}} |
| Three status states shown by color + icon + word | R5, R6 | Iteration 1 (sunlight); low-text design {{medhi2007}}; contrast criteria {{w3c2018}} |
| Four-field visit form with defaults | R3 | Iteration 1 (fields skipped); register used only at clinic for vitals |
| Quiet sync badge, not a modal | R4, R10, R12 | Iteration 2 (5 of 5 disliked dialog); feedback without interruption {{norman2013}} |
| Local-first data model; every action completes on device | R1, R4 | Intermittent coverage (Ch. 3); local-first ideals {{kleppmann2019}}; constraints of developing regions {{brewer2005}} |
| Language toggle in header (EN / SW) | R5 | Iteration 2 (3 of 5 could not find it in settings) |
| Minimum touch target of 48 × 48 dp; text at least 16 sp | R6 | Iteration 2 (mis-taps); target size criteria {{w3c2018}} |
| No map, no GPS; landmarks in text | R12, R7 | Iteration 3 build constraints; register practice |
| PIN on open; data encrypted on device | R9 | Supervisor interviews (shared phones) |
| Printable register-format export | R11 | Supervisor interviews (audit requirement) |
| Weekly district summary screen for supervisors | R8 | Supervisor mockup review |

*Note.* dp = density-independent pixels; sp = scale-independent pixels.

### 4.5.1 List-first follow-up

The home screen is a single scrolling list divided into three groups. Overdue patients come first, marked with a red band, an exclamation icon, and the word "Overdue" with the number of days late. Patients due today follow, in amber with a clock icon. Upcoming patients, due within the next seven days, appear last in green with a calendar icon, collapsed by default. Each row shows the patient's name, village and landmark, the condition, and the last visit date.

> **Figure 4.1** Dose home screen (iteration 3). Header: app name, sync badge, EN / SW toggle. Body: "Overdue (4)" group with red rows; "Due today (6)" group with amber rows; collapsed "Upcoming (11)". Each row: patient name, village and landmark, condition icon, days overdue or last visit date. A single "Search" control sits at the bottom of the screen.

This design addresses the central failure observed in the contextual inquiry, that missed follow-ups went unnoticed, by turning the absence of a visit into something visible. In {{norman2013|n}}'s terms, the list makes the state of the work perceivable, closing what he calls the gulf of evaluation: the CHW does not have to work out from a register who is late; the screen says so. It also follows the principle of reducing short-term memory load {{shneiderman2016}}, since the CHW no longer has to carry the week's missed patients in their head. Rows are sorted by days overdue, then by village, matching the CHWs' walking routes.

### 4.5.2 A four-field visit form

The visit form has four fields: visit outcome (seen, not at home, moved, referred), medication status (taking as prescribed, missed some doses, stopped, not known), next follow-up date, and an optional short note. Outcome defaults to "seen," medication status to the previous visit's answer, and the follow-up date to the interval set for the patient's condition. A visit can therefore be saved in three taps when nothing has changed, meeting R3, while still allowing any field to be changed when it has. Medication status uses icons alongside words, following the low-text principles described by {{medhi2007|n}}, and its categories match those already used in supervisors' monthly reports.

Defaults are a form of error prevention, but they also carry a risk: a hurried CHW may save a default that is not true. I mitigated this in two ways. The defaulted fields are shown with a lighter background until touched, and the confirmation names the patient and the outcome. These choices draw on the rule that the design should offer informative feedback and make actions easy to reverse {{shneiderman2016}}; a visit saved in error can be corrected for 24 hours, after which a correction is recorded as a new entry rather than an edit (Chapter 5 explains why).

### 4.5.3 Quiet sync

The sync decision was the most debated in my journal. Users arguably must be told clearly when data has not reached the server, but iteration 2 showed that a blocking dialog was experienced as an interruption and, worse, as a demand for data spending. The design that emerged treats sync as a background fact rather than an event. A small badge in the header shows a cloud icon. When records are waiting, it shows their number; when all records have been sent, it shows a check mark; when the app has not synced for more than three days, the badge turns amber and the patient list shows a single line of text asking the CHW to visit an area with coverage.

This follows {{norman2013|n}}'s argument that feedback should be immediate and informative but not annoying, and that too much feedback is itself a usability problem. It also reflects the local-first position that the device holds the primary copy of the data and the network is an opportunity rather than a precondition {{kleppmann2019}}. Whether the badge would be read correctly by CHWs who had not seen its design evolve was an open question at this point; Chapter 6 reports that it was not always read as intended.

### 4.5.4 Local-first by design

The final decision is architectural but has direct consequences for the interface. Because coverage in Lowmoor was intermittent and data bundles were shared, I designed every user action to complete on the device, with no screen depending on a server response. This choice is consistent with long-standing observations about technology in developing regions, where connectivity and cost cannot be assumed {{brewer2005}}, and with design lessons from earlier mobile tools for health workers, which emphasize robustness to intermittent connectivity and fitting existing routines {{derenzi2011}}. In interface terms, this meant no loading spinners, no "retry" errors, and no separate offline mode. How this is achieved technically is the subject of Chapter 5.

## 4.6 Reflections on the design process

Three observations about the process are worth recording before turning to implementation. First, the low-fidelity iteration did more work than expected. Choosing the list-first concept and cutting the form from nine fields to four happened on paper, at almost no cost, and these two decisions shaped everything after them. This is consistent with the usability engineering view that early, cheap prototyping repays its effort most {{nielsen1993}}.

Second, the persistent problem of the project, communicating sync state, could not be resolved by principles alone. The guidance is clear that system status should be visible {{nielsen1993}}, and equally clear that interruptions are costly {{shneiderman2016}}; the design had to find a position between them, and the right position depended on how CHWs understood data and its cost. This is the kind of situated judgment that {{schon1983|n}} describes as reflection-in-action, and it remained only partly settled at the end of iteration 3.

Third, the CHWs who took part in the first two iterations learned the design as it changed; by iteration 2 some were explaining the prototype to me. This was one reason the evaluation used a fresh group.

This chapter has shown how the requirements of Chapter 3 were embodied in a design through three iterations, and traced each key decision to its requirements and evidence, answering RQ2 at the level of interaction design. Chapter 5 completes the answer by describing how the working prototype was built: its offline-first architecture, its sync and conflict handling, its security measures, and the tests used to check it against R1, R4, R7, and R9.
