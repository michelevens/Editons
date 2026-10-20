# Chapter 1: Introduction

This chapter sets out the problem that motivated the project, the aim and research questions that structure it, and the design science approach used to answer them. It closes with the scope of the work, its intended contributions, and a guide to the remaining chapters.

## 1.1 Context and motivation

Long-term medication only works when it is taken, and it is only taken reliably when someone notices the moment it stops being taken. Hypertension and diabetes require treatment that continues for years, often with no symptoms that remind the patient to keep going. Tuberculosis treatment has a continuation phase that runs for months after the patient starts to feel well. In all three conditions, non-adherence is common and has many causes. Some are tied to the patient, such as forgetting, side effects or the belief that treatment is no longer needed. Others belong to the health system, such as stock-outs, long travel, or the absence of anyone who follows up {{osterberg2005}}. {{osterberg2005|n}} make the point that adherence is not only a property of patients. It is shaped by the relationship between patients and the people and systems that support them, and interventions that strengthen that relationship tend to help more than those that simply exhort patients to comply.

In many districts, the people who maintain that relationship between clinic appointments are community health workers (CHWs). They are typically recruited from the communities they serve and trained for specific tasks. They visit households to check whether patients are still taking their medication, whether refills have been collected, and whether a patient needs referral back to the clinic. This thesis is set in Lowmoor District, a peri-urban district where a team of CHWs, each responsible for a defined set of households, follows up patients on long-term treatment for hypertension, diabetes and tuberculosis continuation.

In Lowmoor, as in many similar settings, this follow-up is recorded on paper. Each CHW keeps a register, a ruled exercise book or printed ledger, in which each patient has a row and each visit a column. The register is a robust technology. It needs no battery or signal, and supervisors know how to audit it. It is, however, poor at one task that matters a great deal: telling the CHW, at the start of a day, who is due for a visit and who has slipped past their due date. That information exists in the register only implicitly, distributed across dates written in columns, and recovering it means reading the whole book. In practice, as Chapter 3 shows, CHWs rely on memory and habit, and patients who drop out quietly can go unnoticed for weeks.

Mobile phones appear to offer an obvious remedy, and there is a substantial literature on mobile tools for health workers in low-resource settings {{derenzi2011}}. The World Health Organization's guideline on digital interventions for health system strengthening includes recommendations for digital tracking of clients' health status and for decision support for health workers, while stressing that such tools must suit their context and complement, not replace, functioning health systems {{who2019}}. The evidence that mobile health interventions improve outcomes is, however, mixed. A systematic review of trials found benefit for some interventions, notably text-message support for adherence in specific conditions, but inconsistent effects across many others {{free2013}}. A phone-based tool is therefore not self-evidently useful. Whether it helps depends on how well it fits the work, the people and the infrastructure it lands in.

The infrastructure in Lowmoor is the harder constraint. Mobile coverage is patchy within the district and disappears entirely in some lanes and inside many buildings. CHWs use low-end Android phones, often older models with little memory, and pay for data from shared bundles that run out before the end of the month. The constraints that {{brewer2005|n}} identified for technology in developing regions remain recognizable here: intermittent connectivity, limited and unreliable power, and costs that fall on users who can least absorb them. A follow-up tool that assumes a constant connection, a recent phone or a generous data plan would fail in exactly the places where follow-up is hardest.

## 1.2 Problem statement

The problem this thesis addresses can be stated in two parts. First, CHWs in Lowmoor have no reliable way to see, at the point of work, which of their patients are due or overdue for a medication follow-up visit. The paper register holds the information but does not surface it, so missed follow-ups go unnoticed until a supervisor's monthly review or, worse, until the patient returns to the clinic unwell. Second, the obvious technical remedy, a mobile application backed by a central database, conflicts with the conditions of the work. Connectivity is intermittent, devices are constrained, data costs fall on the worker, and the register's role as an auditable record cannot simply be abandoned.

The design problem, then, is not whether to digitize follow-up but how to do so in a way that works when the network does not, on the phones CHWs already have, without adding cost or friction to visits that are already short, and without breaking the supervisory and audit practices built around the paper register. This problem is practical, but it also raises a research question. The design knowledge needed to resolve it, such as how an interface should communicate synchronization state to a user who cannot assume connectivity, or how a home screen should be organized around obligations rather than records, is not yet well articulated for this kind of setting. Chapter 2 develops this gap in more detail.

## 1.3 Aim and research questions

The aim of this project was to design, build and evaluate an artifact, named Dose, that helps CHWs see who is due or overdue for follow-up and record visits offline. Three research questions structured the work:

- **RQ1.** What requirements do CHWs and supervisors have for a follow-up tool in an intermittently connected setting?
- **RQ2.** How can those requirements be embodied in an offline-first mobile design?
- **RQ3.** How usable and useful is the resulting prototype for CHWs in simulated field tasks?

The questions build on one another. RQ1 is answered empirically, through contextual inquiry with CHWs and supervisors, and its answer takes the form of a prioritized requirements specification. RQ2 is answered constructively, through iterative design and implementation. Its answer is the artifact itself together with the design rationale that links each major decision to a requirement and to evidence. RQ3 is answered evaluatively, through a task-based usability study with CHWs who had not taken part in the requirements work. The study also traces each requirement to evidence of whether it was met.

The phrase "usable and useful" in RQ3 was chosen deliberately. Usability, meaning whether CHWs can complete core tasks efficiently and with acceptable satisfaction, can be assessed in a simulated setting. Usefulness, meaning whether the tool would change follow-up practice and ultimately adherence, cannot be fully assessed without field deployment. RQ3 therefore asks how far a simulated evaluation can speak to usefulness, and Chapter 7 is explicit about where that limit lies.

## 1.4 Research approach

The project followed design science research, a paradigm in which knowledge is produced by building and evaluating artifacts that address identified problems {{hevner2004}}. {{hevner2004|n}} frame design science in information systems as an interplay of three cycles. A relevance cycle connects the work to the environment in which the problem arises. A rigor cycle connects it to the existing knowledge base. A central design cycle iterates between building and evaluating. This framing suited the project because Dose had to answer to both. It needed to be relevant to CHWs' actual practice, and it needed to be grounded rigorously in what is already known about mobile health, constrained settings and interface design.

To organize the work in time, I adopted the Design Science Research Methodology (DSRM) of {{peffers2007|n}}. DSRM specifies six activities: problem identification and motivation; definition of the objectives for a solution; design and development; demonstration; evaluation; and communication. Table 1.1 maps these activities onto the chapters of this thesis.

**Table 1.1** Mapping of DSRM activities to thesis chapters

| DSRM activity | What it involved in this project | Chapter |
|---|---|---|
| 1. Problem identification and motivation | Adherence and follow-up problem; constraints of the setting | 1, 2 |
| 2. Objectives of a solution | Contextual inquiry; requirements R1–R12 | 3 |
| 3. Design and development | Three design iterations; working prototype | 4, 5 |
| 4. Demonstration | Simulated field day with the working prototype | 6 |
| 5. Evaluation | Usability study (n = 12); requirements traceability | 6 |
| 6. Communication | This thesis; design knowledge statements | 7 |

*Note.* DSRM allows entry at different points. This project used a problem-centered entry, starting at activity 1.

DSRM is a process model rather than a theory of how design produces knowledge. For the latter, I drew on two complementary positions. The first is research through design as articulated by {{zimmerman2007|n}}, in which the act of designing is itself a way of inquiring. An interaction design contribution is judged by the quality of its process, the novelty of what it proposes, its relevance to a real situation, and whether others can extend it. The second is {{schon1983|n}}'s account of the reflective practitioner. Schön describes design as a reflective conversation with the situation: the designer frames a problem, makes a move, and listens to how the situation responds. This shaped how I kept records during the project. Alongside formal data collection, I kept a design journal in which I noted each significant decision, the alternatives I considered, and what I learned when a prototype failed. Chapters 4 and 7 draw on that journal.

Within this frame, specific methods were chosen for each phase. Contextual inquiry {{holtzblatt2017}} and thematic analysis {{braun2006}} were used for requirements (Chapter 3). Iterative prototyping with heuristic review {{nielsen1993}} was used for design (Chapter 4). Unit, conflict and performance testing were used for implementation (Chapter 5). Task-based usability testing with think-aloud and the System Usability Scale was used for evaluation {{lazar2017;brooke2013}} (Chapter 6). The justification for each method is given in the chapter that uses it.

## 1.5 Scope and delimitations

Several boundaries were set at the outset, and they should be stated plainly because they shape what the findings can claim.

The artifact supports follow-up of patients already on treatment. It does not diagnose, prescribe or give clinical decision support, and it does not replace the clinic's own records. It holds the minimum patient information needed to schedule and record follow-up: a name, household location, condition category, due date and visit outcomes. The conditions in scope are hypertension, diabetes and tuberculosis continuation, the three for which Lowmoor CHWs held follow-up responsibilities during the study period.

The project did not integrate with any national health information system. Synchronization was to a project server operated for the study. Integration is discussed as further work in Chapter 7. The evaluation was a usability study in a simulated field setting, not a field deployment. No clinical or adherence outcomes were measured, and none are claimed. The study population was limited to CHWs and their supervisors. Patients were not participants, and the patient records used in design and evaluation were fabricated test data.

Finally, the setting is one district. Requirements derived from six CHWs and two supervisors in Lowmoor may not transfer to districts with different staffing, connectivity or supervisory arrangements. Chapter 3 discusses this limitation where the requirements are presented, and Chapter 7 returns to it.

## 1.6 Contributions

The thesis makes three contributions, in line with the expectations of design science that a project should yield both an artifact and knowledge that extends beyond it {{hevner2004;zimmerman2007}}.

The first is the artifact: Dose, an offline-first follow-up tool for CHWs that runs as an Android application of 11.8 MB on devices with 1 GB of RAM. Its architecture keeps the device's local store as the primary copy of the data and synchronizes opportunistically.

The second is an empirically grounded requirements specification (R1–R12) for follow-up tools in intermittently connected settings, together with the evidence behind each requirement. Others designing for similar settings can reuse, adapt or contest it.

The third is a small set of design knowledge statements derived from the iterations and the evaluation. The two most developed are "list-first follow-up", the proposition that a follow-up tool's home screen should present obligations (who is due, who is overdue) rather than records, and "quiet sync", the proposition that synchronization status in an intermittently connected tool should be ambient and non-blocking rather than modal. Chapter 6 shows that "quiet sync" also has a cost, because CHWs misread the sync badge in the evaluation. Chapter 7 reflects on what that implies for the principle.

## 1.7 Structure of the thesis

The remainder of the thesis follows the DSRM sequence shown in Table 1.1.

Chapter 2 reviews the background and related work. It covers medication adherence and follow-up, the evidence on mobile health, mobile tools for CHWs, design for constrained and low-literacy settings, offline-first and local-first software, and the methodological literature on design science and usability evaluation. It ends by identifying the gap this project addresses.

Chapter 3 reports the contextual inquiry with CHWs and supervisors and the analysis that produced requirements R1–R12, answering RQ1.

Chapter 4 describes the three design iterations, from paper prototypes through a clickable prototype to the working prototype. It presents design rationale tables linking each decision to a requirement and to evidence. Chapter 5 describes the implementation of the working prototype, including its local storage, encryption, synchronization and testing. Together these chapters answer RQ2.

Chapter 6 reports the usability evaluation with twelve CHWs and the supervisor walkthrough of the weekly summary. It traces each requirement to evidence of whether it was met, answering RQ3.

Chapter 7 reflects on the project as a whole. It discusses the contributions, the limitations of a simulated evaluation, the next steps toward a field pilot, and my own learning as a designer.

The appendices contain the full requirements specification (Appendix A), the usability test script and questionnaire (Appendix B), and a statement on artifact availability (Appendix C).

The next chapter turns to the literature, beginning with the problem Dose exists to address: why patients stop taking long-term medication, and what follow-up can do about it.
