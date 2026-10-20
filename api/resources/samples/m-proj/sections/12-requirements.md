# Chapter 3: Understanding the Context: Requirements

This chapter answers RQ1: what requirements do CHWs and supervisors have for a follow-up tool in an intermittently connected setting? In DSRM terms it covers the second activity, defining the objectives of a solution {{peffers2007}}, and in the vocabulary of {{hevner2004|n}} it is the main expression of the relevance cycle. Section 3.1 justifies the choice of contextual inquiry. Sections 3.2 to 3.4 describe the setting, participants, data collection and analysis. Section 3.5 presents five themes, and Section 3.6 derives twelve requirements from them. Section 3.7 reports how the requirements were checked with participants, and Section 3.8 discusses the limitations of this phase.

## 3.1 Approach: why contextual inquiry

A survey or interviews alone would have captured what CHWs say about their work rather than what they do, which matters most for tacit practices such as deciding whom to visit first. Joint workshops risked reproducing workplace hierarchy, with CHWs deferring to supervisors' account of how follow-up ought to work.

Contextual inquiry addresses these problems by placing the researcher in the work as it happens {{holtzblatt2017}}. {{holtzblatt2017|n}} describe it as an apprenticeship. The designer observes a person doing real work in its real setting, asks about what is happening while it happens, and shares interpretations back so the participant can correct them. The method surfaces workarounds, artifacts and breakdowns that participants take for granted, and its data can be consolidated through interpretation sessions and affinity diagrams {{holtzblatt2017}}. It was a good fit for a problem in which the central difficulty, knowing who is due, was handled largely through habit and memory that CHWs did not think to mention.

I combined shadowing days with CHWs and semi-structured interviews with their two supervisors. Supervisors' work happens mostly in an office and at monthly meetings, where shadowing would have yielded little, so an interview built around a walkthrough of registers and report forms was the closest practical substitute.

> **Sample note.** The data reported in this thesis are synthetic, created to illustrate how such a study is reported. No participants were recruited.

The study was approved by the School of Computing Research Ethics Committee at Kestrel Bay University. All participants gave written informed consent. Patients encountered during shadowing were told who I was by the CHW and asked whether they were content for me to be present. I recorded no patient names or identifying details, and I did not photograph register pages that showed patient information.

## 3.2 Setting and participants

Lowmoor District is a peri-urban district at the edge of a growing town. It combines densely built settlements with lower-density smallholdings. Its CHWs are attached to a district health center and each is responsible for a set of households. Their duties include, among others, follow-up of patients on long-term medication for hypertension, diabetes and tuberculosis continuation. Each CHW keeps a follow-up register and submits a monthly tally to a supervisor.

Six CHWs took part in the requirements phase. They were selected purposively, with the help of the supervisors, to vary in experience, caseload and the part of the district they covered, so that the contextual inquiry would encounter a range of working conditions rather than six versions of the same day. Table 3.1 summarizes their characteristics. All names are pseudonyms.

**Table 3.1** Requirements-phase participants (n = 8)

| Pseudonym | Role | Years in role | Follow-up caseload | Area | Phone |
|---|---|---|---|---|---|
| Grace | CHW | 9 | 64 | Dense settlement | Android, 1 GB RAM |
| Joseph | CHW | 2 | 41 | Smallholdings | Android, 1 GB RAM |
| Mercy | CHW | 12 | 87 | Dense settlement | Android, 2 GB RAM |
| Peter | CHW | 4 | 52 | Mixed | Android, 1 GB RAM (shared with family) |
| Ruth | CHW | 6 | 73 | Dense settlement | Android, 2 GB RAM |
| Samuel | CHW | 3 | 58 | Smallholdings | Android, 1 GB RAM |
| Mrs. Adongo | Supervisor | 15 | — | North of district | — |
| Mr. Kiprop | Supervisor | 7 | — | South of district | — |

*Note.* Caseload is the number of patients on long-term medication follow-up at the time of shadowing. Phone details were reported by participants.

All six CHWs owned or had regular use of an Android smartphone, and all used it for calls and messaging. None used it for any part of follow-up work. Four of the six bought data in small bundles that they described as shared, either with family members or, in Peter's case, together with the phone itself.

## 3.3 Data collection

I shadowed each CHW for one working day, giving six shadowing days in total over three weeks. A day typically began at the health center or at the CHW's home, continued through household visits, and ended with the CHW updating the register in the evening. I followed the contextual interview structure described by {{holtzblatt2017|n}}. A brief opening conversation established what the CHW planned to do and why. During the work, I observed and asked short questions at natural pauses rather than during patient conversations. At the end of the day I summarized what I had seen and asked the CHW to correct my interpretation.

I took handwritten field notes recording activities, artifacts in use, breakdowns and explanations, and wrote them up the same evening. With consent, I audio-recorded the closing conversation of each day; these recordings ranged from 18 to 34 minutes. Each supervisor interview lasted about 50 minutes and was audio-recorded. Each was organized around a walkthrough of a CHW register, the monthly tally form and the supervisor's own records. Recordings were transcribed in English. Where participants spoke in Kiswahili, I worked from a translation checked by a bilingual colleague.

## 3.4 Analysis

Analysis proceeded in two strands that converged. The first followed contextual design practice {{holtzblatt2017}}. After each shadowing day I held a short interpretation session, sometimes with my supervisor and sometimes alone, in which I worked through the day's notes and wrote individual observations, breakdowns and insights on separate notes. The six days produced 312 notes. I then built an affinity diagram, grouping the notes from the bottom up by similarity of meaning rather than by predefined categories. This yielded 41 first-level groups, which clustered into 11 higher-level groups.

The second strand applied thematic analysis to the transcripts and written-up field notes, following the six phases described by {{braun2006|n}}. The phases are familiarization with the data, generating initial codes, searching for themes, reviewing themes, defining and naming themes, and producing the report. I coded inductively at a semantic level, because the purpose was to inform design rather than to theorize the work, and used the affinity groups as a cross-check when reviewing themes. {{braun2006|n}} stress that themes are constructed by the researcher rather than discovered in the data. In keeping with that view, I kept a record in my design journal of how the themes changed between the first and final versions. The most significant change was that an early theme, which I called "lack of time", dissolved on review into the more specific themes of interrupted visits and invisible obligations.

Five themes resulted. Each is presented below with its supporting evidence and the design implications I drew from it.

## 3.5 Findings

### 3.5.1 Theme 1: Obligations are invisible in the register

Every CHW's register contained the information needed to know who was due, but none of them read it that way. The register was organized by patient, one row per patient and dates of visits across the columns. Knowing who was due meant scanning every row, finding the last visit and working out whether the interval had passed. No CHW did this systematically. Instead, CHWs planned their days by area, visiting the households along a route, and relied on memory for who needed to be seen.

> Mostly I know them. I know who is due because I know the people. The book is for writing, not for looking. (Mercy, CHW, 12 years)

This worked poorly for patients who had drifted. On two shadowing days I observed a CHW discover, while writing up the register in the evening, a patient whose last recorded visit was well past due. Joseph, the least experienced CHW, kept a separate notebook listing patients by week, a workaround he had invented to compensate for not yet knowing his patients.

> When I started I missed some people. Nobody told me. Then I made this list, but if I forget to copy it, it is wrong. (Joseph, CHW, 2 years)

The design implication was clear and became the foundation of the project. A follow-up tool should lead with obligations, showing who is due today and who is overdue, rather than with records. This is the origin of R2 and of the list-first home screen described in Chapter 4.

### 3.5.2 Theme 2: Visits are short and interrupted

Follow-up visits were brief. Most of those I observed lasted between five and fifteen minutes, and they were frequently interrupted by neighbors, children, other household members raising unrelated health concerns, or the CHW's own phone. CHWs rarely wrote in the register during a visit. Most wrote brief notes on a scrap of paper or in their notebook and transferred them to the register later. Two waited until the evening and wrote from memory.

> In the house you are talking, you are listening. If I open the book and start writing, the mama stops talking. (Ruth, CHW, 6 years)

The implication was that recording a visit must take almost no attention. If it could not be done in a few seconds with one hand while still facing the patient, it would be deferred, and deferred records are where errors enter. This informed R3, which requires that a visit can be recorded in three taps or fewer after selecting the patient, and the four-field visit form described in Chapter 4.

### 3.5.3 Theme 3: Connectivity and data are borrowed

Connectivity in Lowmoor was neither absent nor reliable. On every shadowing day, CHWs moved in and out of coverage, and signal often disappeared inside houses. Data were bought in small bundles and, for most participants, shared. CHWs were acutely aware of data cost and of airtime cost for SMS. Several said that a work tool that consumed their personal data or credit would be resented, or simply turned off.

> The bundle is for the whole house. If the work phone eats the bundle, my children will ask me where it went. (Peter, CHW, 4 years)

Both supervisors anticipated this concern independently. Mr. Kiprop described an earlier reporting scheme in which CHWs were asked to send monthly tallies by SMS. In his account, it lapsed within months because the cost fell on the workers and reimbursement was slow.

> We tried the SMS reports. It worked for two, three months. Then the messages stopped coming, and when I asked, they said, "who is paying?" (Mr. Kiprop, supervisor)

From this theme came R1 (work fully offline), R4 (synchronize whenever any connection appears, without losing data in conflicts) and R10 (no SMS cost to the CHW). It also shaped R7, because phones that are shared or low on storage cannot accommodate a large installation.

### 3.5.4 Theme 4: The phone is small, old, and someone else's too

Most CHWs' phones were entry-level devices with 1 GB of RAM, little free storage and, in several cases, cracked screens. Screen brightness was a recurring problem outdoors, and I observed CHWs shading the screen with a hand or stepping into a doorway to read a message. Peter's phone was shared with his family, which raised a privacy concern he volunteered without prompting. Grace and Samuel said they read English comfortably but preferred Kiswahili for anything long, and all six used Kiswahili with most patients. Battery anxiety was common. Two CHWs carried power banks, and Samuel switched mobile data off between calls to save battery.

> My daughter uses this phone for school things. I don't want her to see the patients' names. (Peter, CHW, 4 years)

From this theme followed R5 (icons with short text and a language toggle between English and Kiswahili), R6 (large touch targets and sufficient contrast, following WCAG 2.1 {{w3c2018}}), R7 (run on 1 GB RAM Android with an APK under 15 MB), R9 (PIN lock and encryption of patient data at rest) and R12 (avoid battery-heavy features such as constant GPS). The language and icon findings are consistent with the literature on designing for varied literacy {{medhi2007}}. The aim was not a text-free interface, which these users did not need, but an interface that does not rely on reading long English text.

### 3.5.5 Theme 5: Supervision runs on the register

For supervisors, the register was not only a working tool but the record of accountability. Mrs. Adongo reviewed each CHW's register monthly, checking for gaps and patterns of missed visits. She signed the page as evidence of review, and registers were inspected during district audits. Both supervisors said they learned about missed follow-ups too late to act, typically at the monthly review and occasionally only when a patient presented at the clinic.

> By the time I see the book, the month is finished. If a patient was missed in the first week, I am seeing it four weeks later. (Mrs. Adongo, supervisor)

Neither supervisor wanted live tracking of CHWs. Mr. Kiprop was explicit that a tool showing where workers were at every moment would damage trust. Both wanted earlier sight of overdue patients, aggregated so that they could support a CHW rather than police her. Both were also clear that, whatever a digital tool did, the district's audit process required a paper record, at least for the foreseeable future.

> If the auditor comes, he wants the book. I cannot show him a phone. (Mrs. Adongo, supervisor)

This theme produced R8 (a weekly supervisor summary) and R11 (export in the format of the paper register for audit). Together with the earlier concern about privacy, it also reinforced R12's exclusion of constant GPS. Constant location tracking would have been costly in battery, and both supervisors judged it unwelcome. This echoes the caution in the literature about how mobile tools reshape supervision {{derenzi2011}}.

## 3.6 Requirements

The themes were translated into requirements through a structured step. For each theme, I listed the breakdowns and needs it contained. I then wrote candidate requirements as testable statements and assigned each a priority using the MoSCoW scheme (Must, Should, Could, Won't). Must requirements were those without which the tool would fail its purpose or could not responsibly be used in the setting. Should requirements were important but could be partly met or deferred in a first prototype. Table 3.2 presents the twelve requirements that resulted. The full specification, including acceptance criteria, is in Appendix A.

**Table 3.2** Requirements for Dose, with priority and source

| ID | Requirement | Priority | Source theme(s) |
|---|---|---|---|
| R1 | Works fully offline; all core tasks available without a connection | Must | 3 |
| R2 | Shows a "due today / overdue" list on opening the app | Must | 1, 5 |
| R3 | Records a visit in ≤ 3 taps after selecting the patient | Must | 2 |
| R4 | Synchronizes whenever any connection appears; conflict-safe, with no lost visits | Must | 3 |
| R5 | Uses icons with short text; local language toggle (English/Kiswahili) | Should | 4 |
| R6 | Provides large touch targets and sufficient contrast for outdoor use | Must | 4 |
| R7 | Runs on 1 GB RAM Android devices; APK < 15 MB | Must | 3, 4 |
| R8 | Produces a weekly summary for supervisors | Should | 5 |
| R9 | Locks with a PIN; patient data encrypted at rest | Must | 4 |
| R10 | Incurs no SMS cost to the CHW | Must | 3 |
| R11 | Exports in paper register format for audit | Should | 5 |
| R12 | Is battery-light; no constant GPS or heavy background activity | Should | 4, 5 |

*Note.* Theme numbers refer to Sections 3.5.1–3.5.5. No requirement was classed as Could or Won't, although several features were deliberately excluded from scope (Section 1.5).

Three observations about the set are worth making. First, the requirements are dominated by context: only R2, R3 and R8 describe follow-up as such. This matches the argument of {{brewer2005|n}} that, in constrained settings, infrastructure and cost are design problems in their own right. Second, two requirements are in tension. R4 calls for synchronization at every opportunity, while R12 and R10 call for minimal battery and cost. The design resolution, opportunistic background synchronization with back-off and no SMS fallback, is described in Chapters 4 and 5. Third, R8 and R11 serve supervisors rather than CHWs. They were classed as Should because a tool must first be worth using for the worker who carries it {{derenzi2011}}.

Some candidates were rejected. SMS reminders to patients fall outside the scope of a CHW's tool, and the mixed evidence on patient-facing messaging {{free2013}} makes them a separate question. Clinical decision support was excluded for the reasons given in Section 2.2, and GPS-based visit verification on the grounds discussed under Theme 5.

## 3.7 Checking the requirements with participants

Before design began, I returned the requirements to participants. In a 40-minute session with each supervisor, and in a group session with four of the six CHWs (Grace, Joseph, Ruth and Samuel; Mercy and Peter were unavailable), I presented each requirement in plain language with the observation or quote behind it. I then asked whether it was right, whether anything important was missing, and whether the priorities made sense.

This check produced two changes. The CHWs asked that R3 count taps from the point of selecting the patient, not from opening the app, because finding the patient was a separate step that they expected to vary. The original wording had counted from the home screen. Mrs. Adongo asked that R8 be framed as a weekly summary rather than a monthly report, because the point was to see problems earlier than the existing monthly cycle allowed. No participant proposed a new requirement, and no requirement was rejected. The CHWs' strongest reaction was to R2.

> If the phone tells me who is late, that is the thing. The rest is good, but that is the thing. (Grace, CHW, 9 years)

## 3.8 Limitations of this phase

Several limitations qualify these findings. Each CHW was observed on a single day, which may not have been typical; none of the days coincided with a monthly meeting or a drug stock-out, both of which CHWs said changed their routines. The CHWs were selected with the supervisors' help, which may have favored conscientious workers. A CHW who struggled more with follow-up might have produced different requirements, or at least different priorities. My presence certainly affected what I observed. Translation of Kiswahili passages introduced a further layer of interpretation.

The requirements are also specific to Lowmoor. Districts with different coverage, devices or audit practices might rank them differently. R11, for instance, depends on a district audit process that requires paper. Following the extensibility criterion of {{zimmerman2007|n}}, I have tried to make the link between each requirement and its evidence explicit, so that designers elsewhere can judge which requirements transfer to their own settings.

## 3.9 Summary

This chapter has answered RQ1. Contextual inquiry with six CHWs and two supervisors, analyzed through affinity diagramming and thematic analysis, produced five themes: obligations are invisible in the register, visits are short and interrupted, connectivity and data are borrowed, the phone is small, old and shared, and supervision runs on the register. From these I derived twelve requirements (R1–R12), eight classed as Must and four as Should, and checked them with participants. The requirements describe a tool that leads with who is due, records visits almost without attention, works offline on modest phones at no cost to the worker, protects patient data on a shared device, and serves supervisors without surveilling CHWs.

The next chapter takes these requirements into design. It describes how three iterations of prototyping, from paper to a working application, turned them into the artifact that became Dose.
