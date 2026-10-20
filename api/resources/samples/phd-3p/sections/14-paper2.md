# Chapter 4: Paper 2 — Worksite Heat and Occupational Injury: A Case-Crossover Study

> **Publication status.** Under review. Rao NS, Otieno J, Benedetti C, Mwangi E. Daily worksite heat exposure and occupational injury in agricultural and construction workers: a time-stratified case-crossover analysis. Submitted to a peer-reviewed occupational epidemiology journal in March 2026; first-round reviewer comments received and addressed. The chapter reproduces the submitted manuscript with minor changes: the abstract is reformatted to the thesis style, cross-references to other chapters have been added, the supplementary tables are integrated into the text, and references are consolidated in the thesis reference list. The candidate's contribution (70%) and the roles of co-authors are set out in the Statement of Contribution.

This chapter is the second of the three papers. Chapter 3 established that the published evidence linking heat to occupational injury was consistent in direction but of low certainty, and that almost every included study assigned exposure from a weather station rather than from the place where work was done. The bridging chapter that preceded this one explained why those two gaps, together with the near-absence of information on how quickly risk follows exposure, motivated a study built on worksite-level exposure estimates and an explicit lag structure. The paper that follows reports that study. Chapter 5 then turns from measuring risk to testing a way of reducing it.

## Abstract

**Background.** Outdoor workers face rising heat exposure, and occupational injury is among its most common and least studied consequences. Most previous estimates rely on temperature recorded at weather stations distant from worksites, assume that risk is confined to the day of exposure, and rarely ask which workers carry the excess risk. We estimated the exposure–lag–response relationship between daily worksite wet-bulb globe temperature (WBGT) and occupational injury in agricultural and construction workers, and examined effect modification by age, sector and pay basis.

**Methods.** We conducted a time-stratified case-crossover study of accepted injury claims lodged with the Coastal Region Workers' Compensation Board for injuries sustained during the warm season (May to September) of 2015 to 2023. Daily maximum WBGT at each worksite was estimated from 46 automatic weather stations with a land-surface correction validated against on-site loggers. Counts were analysed with conditional Poisson regression and a distributed lag non-linear model over lags 0 to 3 days, adjusting for fine particulate matter (PM2.5) and public holidays according to a directed acyclic graph. We estimated the injury fraction attributable to WBGT above a reference of 24 °C and ran sensitivity analyses that included alternative lag windows, exclusion of heatwave days and a negative-control outcome (claims dated to non-work days).

**Results.** The analysis included 18,406 injury claims (agriculture 7,920; construction 10,486). Injury risk was flat below about 26 °C WBGT and rose steadily above it. The cumulative relative risk (RR) at 31 °C compared with 24 °C was 1.21 (95% CI 1.12–1.31), almost all of it on the day of exposure (lag 0 RR 1.16, 1.09–1.24). Associations were stronger in workers under 25 years (RR 1.34, 1.17–1.54) and in agricultural workers paid by piece rate (RR 1.38, 1.20–1.59) than in hourly-paid agricultural workers (RR 1.13, 1.00–1.27; p for difference 0.03). An estimated 4.1% (empirical 95% CI 2.3–5.8) of injuries, about 755 claims, were attributable to WBGT above 24 °C, most of them on moderately hot rather than extreme days. Results were robust to sensitivity analyses, and the negative-control outcome showed no association (RR 1.04, 0.86–1.26).

**Conclusions.** Worksite heat is associated with an immediate, dose-dependent increase in occupational injury that begins at WBGT levels well below common heat-alert thresholds. The concentration of excess risk among young and piece-rate workers suggests that how work is organised and paid shapes who is harmed. Heat protection that relies on extreme-heat alerts alone will miss most of the attributable burden.

**Keywords:** occupational heat stress; occupational injury; wet-bulb globe temperature; case-crossover; distributed lag non-linear model; piece-rate pay; agriculture; construction.

## 4.1 Introduction

Heat is an occupational hazard in a direct physiological sense and in a less obvious organisational one. Physiologically, work in a hot environment adds an external heat load to the metabolic heat that the body produces through physical effort, and when the two together exceed the capacity of sweating and skin blood flow to shed heat, core temperature rises, dehydration accumulates and performance degrades {{parsons2014;lucas2014}}. Epidemiologically, these effects are now well documented across a range of outcomes. A systematic review of occupational heat strain found that workers exposed to heat stress experienced more adverse health outcomes and lost a measurable share of their productivity {{flouris2018}}, and broader reviews of heat and health have placed occupational exposure alongside age, chronic disease and social isolation as a principal route by which hot weather harms people {{ebi2021}}. Climate projections suggest that the number of hours in which outdoor work can be done safely will fall in many regions, making heat a growing occupational health and economic problem rather than an occasional one {{kjellstrom2016}}.

Occupational injury occupies an awkward position in this literature. It is common, costly and recorded in administrative systems far more completely than most heat-related illnesses, yet it is rarely the primary focus of heat research. The physiological pathways from heat to injury are plausible and several: fatigue and slowed reaction time, impaired concentration and judgement, sweaty hands and fogged eye protection, the removal of uncomfortable personal protective equipment, and the dizziness that precedes heat exhaustion {{spector2019;lucas2014}}. A review of the injury literature concluded that heat exposure was associated with increased injury risk across a variety of settings, while noting wide differences in exposure metrics, outcome definitions and analytic approaches {{spector2019}}. Our own systematic review and meta-analysis, reported in Chapter 3, pooled 14 studies of occupational injury and estimated that each 1 °C of WBGT above 28 °C was associated with a 5% increase in injury risk (RR 1.05, 95% CI 1.03–1.08), with substantial heterogeneity (I² = 62%) and low certainty of evidence under GRADE.

Three features of the existing evidence limit what it can tell an employer, a regulator or a union about when and for whom to act. The first is exposure measurement. In Chapter 3, almost all included studies assigned each injury the temperature recorded at the nearest weather station or a regional average. Weather stations are sited to represent free-air conditions over short grass, away from buildings and paved surfaces, and they do not capture the radiant heat of bare soil, concrete slabs, roofing and machinery that defines conditions on many outdoor worksites {{budd2008;parsons2014}}. Air temperature alone also omits humidity and solar radiation, both of which contribute to heat stress and are incorporated in WBGT, the index most widely used in occupational heat standards {{budd2008}}. Exposure misclassification of this kind is likely to be non-differential with respect to injury and therefore to bias estimates towards the null, but it also blurs the shape of the exposure–response curve, which is precisely what a threshold-based heat plan needs.

The second limitation concerns timing. If heat increases injury mainly through acute impairment, the excess risk should appear on the day of exposure and not afterwards. If it acts partly through cumulative dehydration, sleep disruption or fatigue carried over between shifts, risk might persist for a day or two after a hot day. Studies that use only same-day temperature cannot distinguish these possibilities, and studies that average exposure over several days can conceal an immediate effect within a smoothed estimate. Distributed lag non-linear models (DLNMs) were developed to represent simultaneously the non-linear shape of an exposure–response relationship and its distribution over lags {{gasparrini2010}}, and they have become standard in research on temperature and mortality {{gasparrini2015}}. They have been applied far less often to occupational injury.

The third limitation concerns who bears the risk. Average effects can hide large differences between groups whose work is organised differently. Young workers may be less experienced at recognising early heat strain or less able to refuse work. Workers paid by piece rate (per bin of fruit picked, per row planted, per unit installed) face a direct financial cost for every pause, and so may continue working through warning symptoms that hourly-paid colleagues would act on {{kjellstrom2016;lucas2014}}. Pay basis is seldom recorded in health datasets, and to our knowledge no previous study has estimated whether it modifies the relation between heat and injury.

The aim of this study was to estimate the relationship between daily worksite heat exposure and occupational injury among agricultural and construction workers using worksite-level WBGT estimates and an explicit lag structure. The specific objectives were:

1. to estimate the shape of the exposure–response relationship between daily maximum worksite WBGT and injury, and the lags over which any excess risk occurs;
2. to examine whether the association differs by age group, sex, sector, injury mechanism and, among agricultural workers, pay basis;
3. to estimate the fraction and number of injuries attributable to WBGT above a reference level; and
4. to assess the robustness of these estimates to alternative modelling choices and to residual bias, using sensitivity analyses and a negative-control outcome.

## 4.2 Methods

### 4.2.1 Study design and reporting

We used a time-stratified case-crossover design, in which each injury serves as its own control: exposure on the day the injury occurred (the case day) is compared with exposure on referent days drawn from the same calendar month, year and day of the week for the same location. Because comparisons are made within a short period for the same worksite catchment, the design removes confounding by characteristics that are stable over a month, including the worker's sex, age, occupation, employer and baseline injury risk, and by long-term trends and seasonality. Time-stratified referent selection also avoids the overlap bias that affects some other referent schemes [source needed]. The study is reported in accordance with the STROBE statement for observational studies {{vonelm2007}}; the corresponding checklist items are cross-referenced in the thesis appendices.

### 4.2.2 Setting and data sources

The Coastal Region is a mixed agricultural and urbanising region with a warm, humid summer. Its main agricultural activities are field vegetables, orchard fruit and vineyard work, much of which is seasonal and performed by a combination of resident and migrant workers. Construction activity is concentrated in and around three growing towns and along a coastal transport corridor, and includes residential building, civil works and road maintenance.

Injury data came from the Coastal Region Workers' Compensation Board (the Board), which administers the statutory no-fault compensation scheme for employees in the region. Employers are required to notify the Board of workplace injuries that result in medical treatment beyond first aid or in time lost from work, and workers may also lodge claims directly. Each accepted claim records the date and, where known, the time of injury; the worker's age, sex and occupation; the employer's industry classification; a free-text and coded description of the injury nature, body part and mechanism; a workplace address, which is usually the employer's registered site rather than the place where the injury happened; and a free-text location description. The claim itself does not reliably record how the worker was paid. We therefore derived remuneration basis (hourly or salaried versus piece or task rate) from information beyond the individual claim: the employer's wage-category declarations to the Board for premium purposes, which classify the workforce by pay arrangement for each season, combined with the occupation and task codes on the claim. A claim was classed as piece-rate when the employer declared piece or task-rate pay for that occupation in that season. The Board provided a de-identified extract of all accepted claims from 1 January 2015 to 31 December 2023 for employers classified in agriculture (including horticulture and viticulture) and in construction.

Meteorological data were supplied by the regional meteorological service for 46 automatic weather stations operating throughout the study period. Each station recorded hourly air temperature, relative humidity, wind speed and global solar radiation. Daily fine particulate matter (PM2.5) concentrations were obtained from nine regional air quality monitors, and public holidays from the regional government calendar.

### 4.2.3 Study population and outcome

The outcome was an accepted claim for an acute occupational injury sustained outdoors during the warm season, defined as 1 May to 30 September in each year. We restricted analysis to the warm season because the research question concerns heat, because winter injury patterns differ in kind (for example, slips on wet or icy surfaces), and because a single exposure–response function fitted across the whole year would be dominated by seasonal patterns of work that the case-crossover strata could control only imperfectly.

We excluded claims for injuries that occurred outside the warm season; claims for injuries sustained indoors (for example, in packing sheds, workshops or site offices) identified from the location and mechanism fields; claims coded as heat-related illness, because these are by definition caused by heat and would mechanically inflate the association; claims whose worksite could not be geocoded; and claims whose worksite lay more than 40 km from any weather station. Claims whose recorded injury date fell on a day the Board's records identified as a non-work day for that worker (a weekend or rostered day off with no recorded shift, or a public holiday on which the employer reported no work) were set aside from the main analysis and used as a negative-control outcome (Section 4.2.9). Injury mechanism was grouped into six categories from the Board's coded fields: falls (on the same level and from height); cuts, lacerations and punctures; struck by or against an object; overexertion and sprains; machinery and vehicle incidents; and other.

### 4.2.4 Exposure assessment

The exposure was the daily maximum WBGT at the worksite during working hours, defined as 06:00 to 18:00. WBGT combines natural wet-bulb temperature, globe temperature and dry-bulb air temperature, and so reflects humidity, radiant heat and air movement as well as air temperature; its limitations, including its insensitivity to the evaporative restriction imposed by clothing and its imperfect representation of very humid conditions, are well described {{budd2008;parsons2014}}. We chose it because it is the index on which most occupational heat guidance, including the regional guidance in force during the study period, is based, so that our results can be read directly against the thresholds used in practice.

Exposure estimation proceeded in three steps, developed and implemented by JO with NSR. First, hourly outdoor WBGT was estimated at each of the 46 stations from the recorded air temperature, humidity, wind speed and solar radiation using a physically based model of the natural wet-bulb and black-globe temperatures [source needed]. Second, because the workplace address on a claim usually identifies the employer's registered site and not the place of work, each claim's worksite was located from the free-text location description, matched where possible to the employer's registered farm blocks or active construction permits, and assigned to the nearest station; the median distance from worksite to station was 11.8 km (interquartile range 6.2–19.5 km). Third, we applied a land-surface correction to account for the difference between conditions at the station and those at the worksite. Weather stations are typically sited over irrigated short grass, while worksites in our data were dominated by bare or cropped soil, sealed surfaces and partially built structures, all of which absorb and re-radiate more solar energy. The correction added to the station-based hourly globe temperature an offset that depended on the land-cover class of the worksite (from a regional land-cover map), the hour of the day and the measured solar radiation; the offset coefficients were estimated by regression from a validation campaign in which WBGT loggers were deployed at 18 worksites (nine agricultural, nine construction) during the 2021 and 2022 warm seasons, yielding 3,112 logger-days.

In that validation sample, the uncorrected station-based estimates underestimated logger-measured daily maximum WBGT by a mean of 1.6 °C, with a root mean square error (RMSE) of 2.1 °C. After correction, mean bias was −0.2 °C and RMSE 1.1 °C, and the correlation between estimated and measured daily maxima was 0.91. The correction was estimated in leave-one-site-out cross-validation, so these figures describe performance at worksites not used to fit the coefficients. Chapter 2 sets out the exposure model and validation in more detail.

For each claim we derived the corrected daily maximum WBGT at the worksite for the case day, each referent day and the three days preceding each of them, giving the exposure history needed for lags 0 to 3. Because the analysis was conducted on counts aggregated by station catchment (Section 4.2.6), we also computed, for each catchment-day, the mean corrected worksite WBGT across the land-cover classes weighted by the land-cover composition of claims in that catchment over the whole study period. In practice the two approaches gave nearly identical results, because worksites assigned to the same station varied little in land-cover class within sector.

### 4.2.5 Confounding and causal assumptions

We specified the causal assumptions of the analysis in a directed acyclic graph (DAG) before examining the outcome data, following the principle that adjustment sets should be derived from explicit causal structure rather than from statistical associations in the data {{greenland1999}}. The DAG is summarised in Figure 4.1.

> **Figure 4.1** Directed acyclic graph for the effect of daily worksite WBGT on occupational injury. Nodes: season and long-term trend; day of week; worksite catchment and its fixed characteristics; worker and employer characteristics (stable within month); air temperature, humidity, solar radiation and wind (components of WBGT); daily WBGT; PM2.5; public holiday; work volume on the day (hours worked, number of workers on site); occupational injury. Arrows: season → WBGT, PM2.5, work volume, injury; day of week → work volume, PM2.5, injury; public holiday → work volume, PM2.5, injury; WBGT components → WBGT; WBGT → work volume (unmeasured; mediating path); WBGT → injury; PM2.5 → injury; weather systems → WBGT and PM2.5. Minimal sufficient adjustment set, given the time-stratified design: {PM2.5, public holiday}.

Three features of the DAG shaped the model. First, season, long-term trend, day of the week and all characteristics of the worker, employer and catchment that are stable within a calendar month are controlled by the design through the time-stratified referent selection, and need no further adjustment. Second, humidity is a component of WBGT rather than a separate cause of the exposure, so adjusting for it in addition to WBGT would remove part of the exposure itself; we therefore did not adjust for humidity, solar radiation or wind separately. Third, PM2.5 and public holidays were identified as confounders. Air pollution episodes in the region tend to coincide with hot, stable anticyclonic conditions and may plausibly affect injury risk through respiratory symptoms or reduced visibility, while public holidays alter both the amount of work done and, through traffic and agricultural burning patterns, air quality. We adjusted for the mean PM2.5 concentration over lags 0 to 1, assigned from the nearest of the nine monitors, and for an indicator of a public holiday on the case or referent day. PM2.5 data were missing for 3.1% of catchment-days, which were imputed from the nearest neighbouring monitor.

The DAG also makes explicit an important limitation. On very hot days, employers may shorten shifts, start earlier or stop work in the afternoon, and some workers may not come to work at all. This reduces the number of person-hours at risk and lies on a path from heat to injury count that does not pass through the worker's physiological state. Because the Board's data contain no denominator of hours worked, we could not adjust for it, and doing so would in any case condition on a mediator. Our estimates should therefore be interpreted as the total association between heat and the number of injury claims, net of any reduction in exposure time that heat itself induces. If work volume falls on hot days, the physiological effect of heat on injury per hour worked is likely to be larger than our estimates.

### 4.2.6 Statistical analysis

Following the equivalence between conditional logistic regression on individual case-crossover data and conditional Poisson regression on aggregated counts, we aggregated claims into daily counts for each of the 46 station catchments, giving a series of 63,342 catchment-days (46 catchments × 9 warm seasons × 153 days). Strata were defined by catchment, year, calendar month and day of the week, so that each stratum contained four or five days. We fitted conditional Poisson models with these strata, allowing for overdispersion by estimating a scale parameter. Conditional Poisson regression gives the same estimates as the conditional logistic analysis of a time-stratified case-crossover design while being computationally simpler with large numbers of strata and allowing for overdispersion directly [source needed].

The relation between WBGT and injury was modelled with a DLNM {{gasparrini2010}}. A DLNM represents exposure through a cross-basis: a bidimensional function that combines an exposure–response basis, describing how risk changes with the level of exposure, with a lag–response basis, describing how the effect of a given exposure is distributed over subsequent days. For the exposure–response dimension we used a natural cubic spline with internal knots at the 50th and 90th percentiles of the warm-season distribution of daily maximum worksite WBGT. For the lag dimension we used a quadratic polynomial over lags 0 to 3 days. The lag window was chosen a priori on physiological grounds: an acute effect of heat on performance and attention should appear within the shift, and any carry-over through dehydration, poor sleep or accumulated fatigue was expected to resolve within two to three days. Alternative windows were examined in sensitivity analyses.

The models were therefore of the form log E(Y_st) = α_s + cb(WBGT_t, lag 0–3) + β1 PM2.5_t + β2 holiday_t, where Y_st is the claim count on day t in stratum s, α_s is the stratum-specific intercept conditioned out of the likelihood, and cb is the cross-basis. Results are presented as cumulative relative risks over lags 0 to 3 at each WBGT value relative to a reference of 24 °C, and as lag-specific relative risks at selected WBGT values. The reference of 24 °C was chosen a priori as a value close to the 13th percentile of the warm-season distribution and within the range below current regional guidance thresholds in which we expected little or no excess risk; because the exposure–response curve proved to be flat below about 26 °C, results are insensitive to moving the reference within that range. The principal contrast, 31 °C versus 24 °C, compares a day at about the 89th percentile with the reference and corresponds approximately to the WBGT at which the regional guidance in force during the study period recommended that heavy work be reduced. Ninety-five per cent confidence intervals (CIs) were derived from the model variance–covariance matrix.

Model choices (number and position of exposure knots, degree of the lag polynomial) were fixed before fitting the main model. We checked the fit of alternatives by the quasi-Akaike information criterion but did not use it to select the main model, in order to avoid data-driven specification. Analyses were conducted in R (version 4.3) using the dlnm and gnm packages.

### 4.2.7 Effect modification

We examined effect modification by age group (under 25, 25 to 44, 45 and over), sex, sector (agriculture, construction), injury mechanism and, among agricultural workers, remuneration basis (piece or task rate versus hourly or salaried). Remuneration basis was examined only in agriculture because piece-rate payment was uncommon in construction claims (5.9%), leaving too few events for a stable estimate. For each modifier we refitted the main model on counts restricted to the relevant subgroup, using the same cross-basis specification and, for comparability, the same reference value. We report the cumulative RR at 31 °C versus 24 °C in each subgroup and test for differences between two subgroups with a z-test on the difference of the log RRs, and for linear trend across the three ordered age groups with a meta-regression of the subgroup log RRs on age group rank. These tests were specified in the analysis plan for age, sector and pay basis; the remaining subgroup comparisons (sex and mechanism) are presented descriptively and should be read as exploratory. With six planned and exploratory comparisons, we interpret p values near 0.05 with caution.

### 4.2.8 Attributable burden

We estimated the number and fraction of injuries attributable to WBGT above the reference value of 24 °C using the backward perspective described for temperature-related mortality {{gasparrini2015}}. For each catchment-day, the attributable number was computed from the cumulative RR corresponding to the WBGT experienced over the preceding lag window, applied to the injury count on that day; these numbers were summed over all days and catchments and divided by the total number of injuries to give the attributable fraction. Empirical 95% CIs were obtained by Monte Carlo simulation, drawing 1,000 sets of coefficients from the multivariate normal distribution of the cross-basis parameters. To examine where the burden fell, we separated the attributable fraction into contributions from moderately hot days (WBGT between the reference and the 95th percentile, 31.8 °C) and extremely hot days (above the 95th percentile).

### 4.2.9 Sensitivity analyses and negative control

We conducted the following pre-specified sensitivity analyses: alternative lag windows of 0 to 1 and 0 to 5 days; alternative exposure–response bases (knots at the 25th, 50th and 90th percentiles; quadratic B-spline); a lag-response modelled with unconstrained integer lags; exclusion of days falling within heatwaves, defined as three or more consecutive days on which the catchment's daily maximum WBGT exceeded its warm-season 95th percentile; omission of the PM2.5 adjustment; and use of uncorrected station-based WBGT in place of the land-surface-corrected worksite estimate. The heatwave exclusion addressed the possibility that our results were driven by a small number of exceptional periods in which reporting behaviour, media attention or work patterns might differ. The comparison of corrected and uncorrected exposure addressed the methodological question raised in Chapter 3 about station-based exposure assignment.

We also used a negative-control outcome. A negative-control outcome is one that shares the potential sources of bias of the main outcome but cannot plausibly be caused by the exposure through the pathway of interest. Claims dated to a non-work day are a natural candidate in a compensation dataset: they are recorded through the same reporting system, by the same employers, and are subject to the same seasonal and administrative patterns, but because the worker was not at the worksite on that day, worksite heat on that day cannot have caused an occupational injury. In practice such claims arise mainly from late recognition of an injury, from errors in the recorded date, or from injuries whose symptoms developed after a shift. An association between worksite WBGT on the recorded date and these claims would therefore indicate residual confounding or a reporting artefact, such as a tendency for claims to be dated to hot days or for hot periods to coincide with administrative peaks. We analysed these claims with the same model and exposure specification as the main analysis.

### 4.2.10 Ethics and data governance

The study was approved by the Kestrel Bay University Human Research Ethics Committee as a secondary analysis of de-identified administrative data, with a waiver of individual consent. The Board's data custodian approved the release and linkage. Worksite locations were geocoded within the Board's secure environment and only the assigned station identifier, land-cover class and derived exposure variables were released to the research team; no worker or employer identifiers left the Board. Cell counts below five are suppressed in all outputs in line with the release agreement.

## 4.3 Results

### 4.3.1 Study population

The Board extract contained 48,381 accepted claims from agricultural and construction employers for 2015 to 2023. Of these, 23,868 related to injuries outside the warm season, 2,611 to injuries sustained indoors and 382 to heat-related illness, and were excluded. A further 1,473 claims could not be geocoded to a worksite and 517 related to worksites more than 40 km from a weather station. Of the remaining 19,530 claims, 1,124 were dated to a recorded non-work day and were set aside for the negative-control analysis, leaving 18,406 claims in the main analysis (Figure 4.2).

> **Figure 4.2** Flow of claims through the study. Accepted claims, agriculture and construction, 2015–2023 (n = 48,381) → excluded: injury outside May–September (n = 23,868) → excluded: indoor injury (n = 2,611) → excluded: heat-related illness (n = 382) → excluded: worksite not geocoded (n = 1,473) → excluded: worksite more than 40 km from a station (n = 517) → eligible claims (n = 19,530) → set aside as negative-control outcome: injury dated to a non-work day (n = 1,124) → main analysis (n = 18,406; agriculture 7,920, construction 10,486).

Characteristics of the included claims are shown in Table 4.1. Most claims were lodged by men (86.4%), although women accounted for a larger share in agriculture (21.6%) than in construction (7.6%). About a fifth of claims (21.0%) were from workers under 25 years. Piece or task-rate pay was recorded for 40.2% of agricultural claims and 5.9% of construction claims. Injury mechanisms differed between sectors in expected ways: falls and being struck by objects were more common in construction, while cuts and overexertion injuries were relatively more common in agriculture.

**Table 4.1** Characteristics of included injury claims by sector, Coastal Region, warm seasons 2015–2023 (n = 18,406)

| Characteristic | Agriculture (n = 7,920) | Construction (n = 10,486) | Total (n = 18,406) |
|---|---|---|---|
| **Sex**, n (%) | | | |
| Men | 6,210 (78.4) | 9,692 (92.4) | 15,902 (86.4) |
| Women | 1,710 (21.6) | 794 (7.6) | 2,504 (13.6) |
| **Age group**, n (%) | | | |
| Under 25 years | 1,742 (22.0) | 2,124 (20.3) | 3,866 (21.0) |
| 25–44 years | 3,690 (46.6) | 5,222 (49.8) | 8,912 (48.4) |
| 45 years and over | 2,488 (31.4) | 3,140 (29.9) | 5,628 (30.6) |
| **Remuneration basis**, n (%) | | | |
| Hourly or salaried | 4,736 (59.8) | 9,871 (94.1) | 14,607 (79.4) |
| Piece or task rate | 3,184 (40.2) | 615 (5.9) | 3,799 (20.6) |
| **Injury mechanism**, n (%) | | | |
| Falls | 1,584 (20.0) | 2,936 (28.0) | 4,520 (24.6) |
| Cuts, lacerations, punctures | 1,821 (23.0) | 1,573 (15.0) | 3,394 (18.4) |
| Struck by or against object | 1,267 (16.0) | 2,307 (22.0) | 3,574 (19.4) |
| Overexertion and sprains | 1,901 (24.0) | 2,202 (21.0) | 4,103 (22.3) |
| Machinery and vehicle | 712 (9.0) | 839 (8.0) | 1,551 (8.4) |
| Other | 635 (8.0) | 629 (6.0) | 1,264 (6.9) |

*Note.* Percentages are of the column total. Remuneration basis was derived from employer wage-category declarations linked to the claim's occupation code (Section 4.2.2).

### 4.3.2 Exposure distribution

Across the 63,342 catchment-days in the analysis, the corrected daily maximum worksite WBGT had a mean of 27.3 °C (standard deviation 2.9 °C) and a median of 27.4 °C. The 5th, 25th, 75th, 95th and 99th percentiles were 22.4, 25.3, 29.4, 31.8 and 32.9 °C respectively. The reference value of 24 °C lay close to the 13th percentile, and the contrast value of 31 °C close to the 89th. Uncorrected station-based WBGT was lower throughout, with a median of 25.9 °C, consistent with the underestimation seen in the validation campaign. Days within heatwaves as defined in Section 4.2.9 made up 7.1% of catchment-days. Daily maximum WBGT and the PM2.5 lag 0–1 mean were moderately correlated (Spearman's ρ = 0.38).

### 4.3.3 Exposure–response and lag structure

Figure 4.3 shows the cumulative exposure–response relationship over lags 0 to 3. Risk was close to flat between about 22 °C and 26 °C WBGT and increased approximately linearly from about 26 °C upwards, with no clear evidence of levelling off in the upper tail, where confidence intervals widened because of the small number of very hot days.

> **Figure 4.3** Cumulative relative risk of occupational injury over lags 0–3 by daily maximum worksite WBGT, relative to 24 °C, with 95% confidence band. The curve lies close to 1.0 from 22 °C to 26 °C, crosses 1.05 at about 27.5 °C, reaches 1.15 at 30 °C and 1.27 at 32 °C. A histogram along the horizontal axis shows the distribution of catchment-day WBGT (median 27.4 °C). Dashed vertical lines mark the reference (24 °C) and the 95th percentile (31.8 °C).

Cumulative relative risks at selected WBGT values are shown in Table 4.2. At 31 °C, compared with 24 °C, the cumulative RR was 1.21 (95% CI 1.12–1.31). The lag-specific estimates at 31 °C show that almost all of this excess occurred on the day of exposure: the lag 0 RR was 1.16 (1.09–1.24), while the estimates for lags 1, 2 and 3 were small and their confidence intervals included 1. There was no evidence of a negative association at later lags that would suggest harvesting, that is, the bringing forward of injuries that would otherwise have occurred a day or two later.

**Table 4.2** Cumulative and lag-specific relative risks of injury by daily maximum worksite WBGT, relative to 24 °C

| WBGT (°C) | Approximate percentile | RR (95% CI) |
|---|---|---|
| **Cumulative, lag 0–3** | | |
| 26 | 27th | 1.02 (0.98–1.06) |
| 28 | 60th | 1.07 (1.03–1.11) |
| 30 | 80th | 1.15 (1.08–1.22) |
| 31 | 89th | 1.21 (1.12–1.31) |
| 32 | 96th | 1.27 (1.15–1.40) |
| **Lag-specific at 31 °C** | | |
| Lag 0 | | 1.16 (1.09–1.24) |
| Lag 1 | | 1.03 (0.98–1.08) |
| Lag 2 | | 1.01 (0.97–1.05) |
| Lag 3 | | 1.00 (0.96–1.04) |

*Note.* Conditional Poisson DLNM with natural cubic spline for exposure (knots at the 50th and 90th percentiles) and quadratic polynomial for lag, adjusted for PM2.5 (lag 0–1 mean) and public holidays. The cumulative RR is the product of the lag-specific RRs.

For comparison with Chapter 3, the slope of the curve between 28 °C and 31 °C corresponds to an increase in risk of roughly 4% per 1 °C of WBGT, close to the pooled estimate of 5% per 1 °C above 28 °C from the meta-analysis, although the two quantities are not identically defined.

### 4.3.4 Effect modification

Subgroup estimates at 31 °C versus 24 °C are shown in Table 4.3. The association was strongest in workers under 25 years (RR 1.34, 1.17–1.54), intermediate in those aged 25 to 44 (RR 1.18, 1.07–1.30) and weakest, with a confidence interval that included 1, in those aged 45 and over (RR 1.12, 0.99–1.27); the test for linear trend across age groups gave p = 0.04. Estimates were similar in men and women, although the interval for women was wide. The association was somewhat stronger in agriculture (RR 1.24, 1.13–1.37) than in construction (RR 1.18, 1.08–1.29), but the difference was compatible with chance (p = 0.45).

Within agriculture, the association was markedly stronger in claims from workers paid by piece or task rate (RR 1.38, 1.20–1.59) than in those from hourly or salaried workers (RR 1.13, 1.00–1.27); the difference between the two log RRs gave p = 0.03. The lag structure differed little between pay groups, with most of the excess on lag 0 in both. Because piece-rate work was more common among younger agricultural workers (46.3% of claims from agricultural workers under 25, compared with 38.7% among those aged 25 and over), we fitted a further model in agricultural workers aged 25 and over only. The piece-rate RR remained elevated (1.33, 1.12–1.58) and the hourly RR was similar to the main estimate (1.11, 0.97–1.27), suggesting that the pay-basis difference was not simply a reflection of age.

Among injury mechanisms, associations were strongest for falls (RR 1.26, 1.10–1.44) and cuts (RR 1.24, 1.07–1.44), and present for being struck by objects (RR 1.19, 1.03–1.37) and overexertion (RR 1.17, 1.02–1.34). Estimates for machinery and vehicle incidents and for other mechanisms were imprecise and their intervals included 1.

**Table 4.3** Cumulative relative risk of injury at 31 °C versus 24 °C WBGT (lag 0–3) by subgroup

| Subgroup | Claims (n) | RR (95% CI) | p for difference or trend |
|---|---|---|---|
| **All claims** | 18,406 | 1.21 (1.12–1.31) | |
| **Age group** | | | 0.04 (trend) |
| Under 25 years | 3,866 | 1.34 (1.17–1.54) | |
| 25–44 years | 8,912 | 1.18 (1.07–1.30) | |
| 45 years and over | 5,628 | 1.12 (0.99–1.27) | |
| **Sex** | | | 0.93 |
| Men | 15,902 | 1.21 (1.11–1.32) | |
| Women | 2,504 | 1.20 (0.98–1.47) | |
| **Sector** | | | 0.45 |
| Agriculture | 7,920 | 1.24 (1.13–1.37) | |
| Construction | 10,486 | 1.18 (1.08–1.29) | |
| **Remuneration basis (agriculture only)** | | | 0.03 |
| Piece or task rate | 3,184 | 1.38 (1.20–1.59) | |
| Hourly or salaried | 4,736 | 1.13 (1.00–1.27) | |
| **Injury mechanism** | | | not tested |
| Falls | 4,520 | 1.26 (1.10–1.44) | |
| Cuts, lacerations, punctures | 3,394 | 1.24 (1.07–1.44) | |
| Struck by or against object | 3,574 | 1.19 (1.03–1.37) | |
| Overexertion and sprains | 4,103 | 1.17 (1.02–1.34) | |
| Machinery and vehicle | 1,551 | 1.10 (0.86–1.41) | |
| Other | 1,264 | 1.13 (0.87–1.47) | |

*Note.* Each subgroup estimate is from a separate model with the same specification as the main model. Tests for age, sector and pay basis were pre-specified; sex and mechanism comparisons are exploratory.

### 4.3.5 Attributable burden

An estimated 4.1% (empirical 95% CI 2.3–5.8) of warm-season injury claims were attributable to daily maximum worksite WBGT above 24 °C. This corresponds to about 755 claims (empirical 95% CI 423–1,068) over the nine seasons, or roughly 84 claims per season. Most of this burden arose on moderately hot days: days between the reference and the 95th percentile (31.8 °C) accounted for an attributable fraction of 3.2% (1.8–4.5), and days above the 95th percentile for 0.9% (0.5–1.3). In other words, about three-quarters of heat-attributable injuries occurred on days that would not have triggered an extreme-heat alert. The attributable fraction was higher in agriculture (4.8%, 2.6–6.9) than in construction (3.6%, 1.7–5.4), and among piece-rate agricultural workers it was 6.7% (3.9–9.3).

### 4.3.6 Sensitivity analyses and negative control

Results of the sensitivity analyses are summarised in Table 4.4. The main estimate was stable across alternative lag windows and exposure bases. Extending the lag window to five days changed the cumulative RR very little, consistent with the absence of effects beyond lag 1. Excluding heatwave days reduced the estimate slightly, to 1.18 (1.08–1.29), indicating that the association was not driven by a few exceptional periods. Omitting the PM2.5 adjustment increased the estimate marginally, as expected given the positive correlation between PM2.5 and WBGT.

Replacing the corrected worksite WBGT with uncorrected station-based WBGT, and comparing the same 7 °C contrast on that scale, attenuated the cumulative RR to 1.17 (1.09–1.26). The attenuation is in the direction expected if the correction reduces non-differential exposure measurement error. Because the uncorrected scale is shifted downwards, the uncorrected model also placed the apparent threshold of rising risk about 1.5 °C lower than the corrected model did, which matters for any attempt to translate station-based results into worksite guidance.

The negative-control analysis of 1,124 claims dated to non-work days showed no association with worksite WBGT on the recorded date (RR at 31 °C versus 24 °C 1.04, 0.86–1.26), and the exposure–response curve for these claims was flat across the WBGT range.

**Table 4.4** Sensitivity analyses: cumulative relative risk of injury at 31 °C versus 24 °C WBGT

| Analysis | RR (95% CI) |
|---|---|
| Main model (lag 0–3; n = 18,406) | 1.21 (1.12–1.31) |
| Lag 0–1 | 1.20 (1.12–1.29) |
| Lag 0–5 | 1.22 (1.11–1.34) |
| Exposure knots at 25th, 50th and 90th percentiles | 1.21 (1.11–1.32) |
| Quadratic B-spline exposure basis | 1.20 (1.11–1.30) |
| Unconstrained integer lags | 1.21 (1.11–1.33) |
| Excluding heatwave days | 1.18 (1.08–1.29) |
| Without PM2.5 adjustment | 1.22 (1.13–1.32) |
| Uncorrected station-based WBGT | 1.17 (1.09–1.26) |
| Negative-control outcome: claims dated to non-work days (n = 1,124) | 1.04 (0.86–1.26) |

*Note.* All models other than the stated variation use the main specification.

## 4.4 Discussion

### 4.4.1 Principal findings

In this time-stratified case-crossover study of 18,406 injury claims from agricultural and construction workers, daily worksite heat exposure was associated with an immediate, dose-dependent increase in occupational injury. Risk began to rise at a daily maximum WBGT of about 26 °C, several degrees below the levels at which occupational heat guidance typically recommends reducing heavy work, and was about 21% higher on a day at 31 °C than on a day at 24 °C. Almost all of the excess occurred on the day of exposure. The association was concentrated among workers under 25 and among agricultural workers paid by piece rate, for whom the relative increase at 31 °C was nearly three times that of their hourly-paid colleagues (38% against 13%). About 4% of warm-season injury claims were attributable to heat above 24 °C, three-quarters of them on days that were hot but not extreme. The results were robust to a range of modelling choices, and a negative-control outcome sharing the reporting pathway of the main outcome showed no association.

### 4.4.2 Comparison with previous studies

The direction and broad magnitude of our main estimate agree with the earlier literature. The review by {{spector2019|n}} found a consistent positive association between heat and occupational injury across settings, and the meta-analysis in Chapter 3 estimated a 5% increase in risk per 1 °C WBGT above 28 °C. Our slope in the upper part of the curve, roughly 4% per 1 °C, is close to that pooled figure. What this study adds is not mainly a different average effect but a clearer picture of its shape, timing and distribution, together with exposure estimates that reflect conditions at worksites rather than at stations.

Three comparisons stand out. The first is the threshold. Many previous studies, including most of those pooled in Chapter 3, modelled temperature linearly above an arbitrary cut-point or in categories, which can hide where risk begins to rise. Our flexible exposure–response function suggests that excess risk begins at moderate WBGT. This is consistent with the observation that heat affects performance and comfort well before it produces clinical heat illness {{lucas2014;flouris2018}}, and with the physiological principle that the body's heat balance under heavy work is compromised at WBGT values that seem unremarkable to an observer {{parsons2014}}.

The second is the lag structure. The concentration of the effect at lag 0 supports an acute mechanism, in which heat impairs attention, coordination, grip or judgement during the shift in which the exposure occurs. We found little evidence of carry-over from cumulative dehydration or poor sleep, which would have produced positive associations at lags 1 or 2. This contrasts with temperature–mortality research, in which heat effects are also short but are sometimes followed by a deficit at later lags suggestive of mortality displacement {{gasparrini2010;gasparrini2015}}. The absence of any such deficit in our data implies that heat-associated injuries were additional rather than merely brought forward, which is what one would expect for an outcome among working-age adults that is not primarily determined by underlying frailty.

The third is the distribution of the burden across days. That most attributable injuries occurred on moderately hot days rather than extreme ones echoes the finding of multicountry mortality research that the bulk of temperature-attributable deaths arise at moderate rather than extreme temperatures, because moderate days are so much more frequent {{gasparrini2015}}. For occupational injury, this pattern has a direct policy implication, discussed below: protection activated only by extreme-heat alerts will address a minority of the problem.

### 4.4.3 Effect modification by age and pay basis

The stronger association in workers under 25 is plausible on several grounds that our data cannot separate. Young workers are more likely to be new to a job and to a particular worksite, may be less practised at recognising and acting on early symptoms of heat strain, may be allocated the most physically demanding tasks, and may feel less able to slow down or ask for a break {{lucas2014;spector2019}}. Older workers, conversely, may have self-selected out of the hottest and heaviest roles, a healthy-worker effect that would attenuate their apparent risk. The trend we observed should therefore not be read as evidence that older workers are physiologically less susceptible to heat, which is unlikely; it more probably reflects who does what work, with what experience and how much autonomy.

The pay-basis finding is, to our knowledge, the first direct estimate of effect modification of the heat–injury association by piece-rate payment. Under piece-rate pay, every minute spent resting, drinking or seeking shade is a minute of lost earnings, and workers who are already near a financial margin have a strong incentive to keep working through the early warning signs of heat strain. The plausibility of this mechanism has been noted in reviews of occupational heat {{kjellstrom2016;lucas2014}}, but it has rarely been testable because health and injury datasets do not usually record how people are paid. That the difference persisted when the analysis was restricted to workers aged 25 and over makes it less likely to be an artefact of the younger age profile of piece-rate workers. Nonetheless, piece-rate work in the Coastal Region is concentrated in particular crops and tasks, orchard picking and vineyard harvest in particular, which differ from hourly-paid work in their physical demands, exposure to direct sun and use of ladders and cutting tools. We could not examine task as a modifier within the case-crossover framework with the coding available, so part of the pay-basis difference may reflect task rather than pay as such. Disentangling the two requires data in which the same tasks are performed under different pay arrangements, or an intervention that changes the cost of resting while leaving the task unchanged.

### 4.4.4 The value of worksite exposure estimation

A secondary aim of this paper was methodological: to test whether correcting station-based WBGT for worksite conditions matters. It did, in two ways. The corrected estimate produced a somewhat stronger association than the uncorrected one, as would be expected if the correction reduced non-differential measurement error. More practically, the uncorrected exposure placed the apparent threshold of rising risk about 1.5 °C lower on the WBGT scale. This is not because risk begins at a lower true temperature, but because stations systematically read cooler than worksites. Any heat plan that sets action thresholds from station-based epidemiology and then applies them to readings taken on site, or the reverse, risks being miscalibrated by roughly this amount. The validation campaign reduced the mean bias of the station-based estimates from 1.6 °C to 0.2 °C, a meaningful improvement at modest cost, and the same approach could be applied in other regions where worksite loggers can be deployed for a season or two. The limitations of WBGT as an index, including its indifference to clothing and to the duration of exposure within the day, remain {{budd2008}}.

### 4.4.5 Strengths and limitations

The main strengths of the study are its size and completeness, drawn from a statutory compensation system covering two high-risk sectors over nine seasons; the use of worksite-level WBGT estimates validated against on-site measurements; the flexible modelling of the exposure–lag–response relationship; the explicit specification of causal assumptions before analysis {{greenland1999}}; the linkage of claims to employer pay declarations, which allowed a novel effect-modification analysis; and the use of a negative-control outcome to probe residual bias. The case-crossover design controls by construction for all individual, employer and worksite characteristics that are stable within a month.

Several limitations should be weighed. First, compensation claims capture only injuries that are reported, accepted and attributed to a worksite. Under-reporting is likely to be greater among migrant, informal and piece-rate workers, who may know less about their entitlements or have more reason to fear the consequences of a claim. Under-reporting that is constant within a month does not bias a case-crossover estimate. If workers were less likely to report injuries on hot days (because they were working longer to make up time, for example), our estimates would be biased towards the null; if they were more likely to report them (because heat made injuries more salient), our estimates would be inflated. The null negative-control result is reassuring about some forms of reporting artefact, but it cannot rule out reporting behaviour that differs specifically between hot and cool working days. Differential under-reporting by piece-rate workers would, if anything, mean that the true pay-basis difference is larger than we observed, unless reporting among piece-rate workers is itself more sensitive to heat.

Second, although our exposure estimates were better than station data alone, they remained estimates. The residual RMSE of 1.1 °C, the reliance on a land-cover map rather than site-specific information, and the use of a single daily maximum rather than the exposure in the hours before the injury all leave room for misclassification. The time of injury was recorded for only part of the claims and we did not use it; doing so would allow hourly exposure to be modelled in future work. Workers also move between sun and shade, and their personal exposure depends on clothing, workload and rest, none of which we observed.

Third, we lacked a denominator of hours worked or workers present. As discussed in Section 4.2.5, if employers curtailed work on hot days, our estimates represent the total association net of that curtailment and understate the effect per hour of work. Conversely, if more workers were hired or hours extended in the hottest weeks of harvest, part of the association could reflect more work being done rather than more risk per hour. Time-stratified referents within the same month limit, but do not eliminate, this possibility, especially in agriculture, where harvest peaks may last only a few weeks. The heatwave exclusion and the similar results in construction, where seasonal fluctuations in workforce size are smaller, offer some reassurance.

Fourth, the subgroup analyses involved several comparisons. The age and pay-basis tests were pre-specified and their direction hypothesised in advance, but a p value of 0.03 is not strong evidence on its own, and the pay-basis finding should be replicated. Pay basis was derived from employer declarations rather than recorded for the individual worker, so some misclassification is likely, particularly where workers were paid a mixture of hourly and piece rates over a season or where an employer's declaration did not match an individual's arrangement. Such misclassification is unlikely to depend on the day's heat, and would tend to narrow rather than create a difference between pay groups.

Fifth, the Coastal Region has a particular climate, labour market and regulatory setting. The absolute thresholds we observed are likely to differ in populations with different acclimatisation, workloads and clothing, although the qualitative pattern of an immediate, moderate-heat effect concentrated in young and piece-rate workers is more likely to generalise.

### 4.4.6 Implications for policy and research

Three implications follow. The first concerns thresholds. If injury risk begins to rise at a daily maximum WBGT of about 26 °C, and most heat-attributable injuries occur on moderately hot days, then heat plans that act only on extreme-heat alerts, or on WBGT thresholds set to prevent clinical heat illness, will leave most of the injury burden unaddressed. A graded approach, in which protective measures begin at moderate WBGT and intensify as it rises, is more consistent with our results and with the broader view that heat-health protection should cover the whole distribution of hot weather, not only its extremes {{ebi2021}}.

The second concerns who is protected. Heat plans written as if all workers were equally able to rest when they need to will not protect those whose pay or position makes rest costly. Our results suggest that pay arrangements should be treated as part of heat protection rather than separate from it: if a rest break costs a piece-rate worker money, a recommendation to rest is unlikely to be followed. Measures such as paying piece-rate workers for mandated rest time, or switching to hourly pay above specified WBGT levels, are worth testing.

The third is for research. Our data show where and when the risk falls but not how it can be reduced. Interventions that reduce heat exposure at work, and the conditions under which they are actually taken up, need to be studied directly, with attention to work organisation as well as physiology. Observational follow-up of heat-plan implementation could also be framed explicitly as the emulation of a target trial, making clear which intervention, population and time zero are being compared {{hernan2016}}. Methodologically, our experience suggests that worksite exposure correction and negative-control outcomes are inexpensive additions that strengthen the inferences available from administrative data.

## 4.5 Conclusions

Among agricultural and construction workers in the Coastal Region, daily worksite heat exposure was associated with an immediate increase in occupational injury that began at moderate WBGT and rose steadily with heat. The excess fell mostly on the day of exposure, mostly on days that were hot but not extreme, and disproportionately on young workers and on agricultural workers paid by piece rate. Heat-protection policies that rely on extreme-heat alerts, and that assume all workers are free to rest, are unlikely to prevent most heat-related injuries. Interventions that build rest into the organisation and payment of work deserve evaluation.

**Funding.** This work was supported by a Kestrel Bay University doctoral scholarship and a School of Public Health research support grant. The funders had no role in the design, analysis, interpretation or decision to submit.

**Data availability.** The claims data are held by the Coastal Region Workers' Compensation Board and are available to approved researchers under the Board's data release procedures. Analysis code is available from the corresponding author.

**Competing interests.** None declared.

This chapter has shown that heat-related injury risk at worksites is real, immediate and unevenly shared, and that the workers who bear the greatest excess are those whose conditions of work make rest most costly. It cannot show whether that risk can be reduced in practice. The bridging chapter that follows explains how these findings shaped the design of the feasibility study reported in Chapter 5.
