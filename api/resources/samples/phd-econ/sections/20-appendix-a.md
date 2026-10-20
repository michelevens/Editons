# Appendix A: Constructing the Labor-Market Panel

This appendix documents how the labor-market panel used in Chapters 4 to 6 is organized and how each variable is constructed from its components. As stated in Chapter 4, the panel is synthetic. It was built to have the structure of a quarterly panel of US commuting zones, and the steps below are written so that each one could be applied, without modification, to administrative sources on employment, earnings, migration and housing. Section A.1 describes the geographic units, Section A.2 the classification of remote-capable workers, Section A.3 the construction of migration flows and the treatment variable, Section A.4 the outcome variables, and Section A.5 the consistency checks applied before estimation.

## A.1 Geographic units and time frame

The unit of observation is a local labor market observed in a calendar quarter. Local labor markets are modelled on commuting zones, the clusters of counties that are standard in US empirical work on local labor markets {{tolbert1996}}. Commuting zones are built by grouping counties according to the strength of commuting flows between them, so that most residents of a zone also work in it. That property is what makes them suitable for this dissertation: a remote worker who moves into a commuting zone spends most of her local budget within it, and the non-tradable service jobs that her spending supports are mostly filled by people who live within it.

The definition of commuting zones depends on methodological choices, including the clustering algorithm and the cutoff used to decide when two counties belong together, and different vintages can produce noticeably different boundaries {{fowler2016}}. The panel therefore fixes a single set of 600 market boundaries for the whole period, so that no change in an outcome can arise from a change in geography. In an application to real data, this would correspond to choosing one vintage of commuting zones and mapping all county-level inputs to it using a fixed crosswalk.

The panel runs from 2017Q1 to 2024Q4, a total of 32 quarters. With 600 markets, it contains 19,200 market-quarter observations and is balanced: every market is observed in every quarter for every variable. The pre-2020 period, 2017Q1 to 2019Q4, provides twelve quarters before any market can be treated. This period is used to fix the employment base against which migration is scaled (Section A.3), to estimate seasonal factors, and to assess pre-treatment trends in the event-study analyses of Chapters 5 and 6.

Markets vary greatly in size. Among the 306 markets that are eventually treated, mean employment in the base period is 142,000 jobs and median employment is 61,000. The gap between mean and median reflects the right skew that one would expect in any set of labor markets that includes a few large metropolitan areas alongside many small and rural ones. Because the main estimators weight markets equally, the estimates in Chapters 5 and 6 describe the typical treated market rather than the typical treated worker.

## A.2 Remote-capable occupations

The treatment is defined by the migration of workers who are able to work remotely. Whether a worker can do so is assigned at the level of the occupation, following the approach of {{dingel2020|n}}, who classify occupations according to whether their task content allows them to be performed entirely at home. Each worker in the migration records carries an occupation code, and each occupation carries a teleworkability indicator. A worker is classified as remote-capable if her occupation is classified as teleworkable.

The classification is binary and occupational. It does not record whether a given worker actually works remotely, and it treats every worker in a teleworkable occupation alike. Chapter 7 discusses the consequences of this choice for measurement. Two features of the construction limit the damage. First, the treatment variable is based on net flows of remote-capable workers relative to a large base, so the classification of any individual matters little; what matters is that net flows of such workers are well measured on average. Second, the occupational classification is held fixed over the whole period, so that changes in measured flows cannot arise from reclassification.

An alternative occupation-level measure of work-from-home feasibility, developed from occupational task data and validated with time-use information {{mongey2021}}, could be used in place of the indicator above. In applications where both measures are available, the treatment could be recoded using each and the cohorts compared; the results in this dissertation use the single classification described here.

## A.3 Migration flows and the treatment variable

### A.3.1 Flows

For each market and quarter, the panel records gross inflows and gross outflows of employed working-age residents, separately for remote-capable and other workers. An inflow is a worker whose residence in quarter $t$ is in market $c$ and whose residence in quarter $t-1$ was in another market; an outflow is the reverse. Moves within a market are not counted. Net in-migration of remote-capable workers is

$$
M_{ct} = \text{In}^{RC}_{ct} - \text{Out}^{RC}_{ct}, \tag{?}
$$

where the superscript $RC$ denotes remote-capable workers. Migration has a seasonal pattern, with more moves in the summer quarters, and a market's raw net inflow in a given quarter can cross a threshold for seasonal reasons alone. Each market's flows are therefore seasonally adjusted before treatment is coded, by subtracting from $M_{ct}$ the market's own mean net inflow in the same quarter of the year over 2017–2019, and adding back the market's overall 2017–2019 quarterly mean.

### A.3.2 Scaling

Seasonally adjusted net inflows are scaled by the market's base employment,

$$
m_{ct} = \frac{M^{SA}_{ct}}{E^{2019}_c}, \tag{?}
$$

where $E^{2019}_c$ is the market's mean total employment over the four quarters of 2019. Scaling by a fixed pre-2020 base, rather than by contemporaneous employment, ensures that the treatment variable does not respond mechanically to the outcomes, in particular to the growth of non-tradable employment that the treatment is meant to explain.

### A.3.3 Treatment coding

A market is treated from the first quarter in which its scaled net inflow exceeds 0.5 percent of base employment for two consecutive quarters. Formally, the treatment date is

$$
G_c = \min\left\{ t \ge 2020\text{Q1} : m_{ct} > 0.005 \ \text{and} \ m_{c,t+1} > 0.005 \right\}, \tag{?}
$$

with $G_c = \infty$ for markets in which the condition is never met by 2024Q4. The treatment indicator is $D_{ct} = \mathbf{1}\{t \ge G_c\}$, and treatment is absorbing: once a market is treated, it remains treated even if later inflows fall below the threshold. The two-quarter requirement guards against coding a market as treated on the basis of a single unusual quarter, and the absorbing convention matches the estimators described in Chapter 4, which are defined for staggered adoption of a treatment that is not reversed.

The condition is evaluated only from 2020Q1, so the twelve quarters from 2017Q1 to 2019Q4 are untreated for every market by construction. Applying the same rule to the pre-2020 period as a check, no market meets the condition in any quarter before 2020, which confirms that the threshold is not crossed routinely by ordinary migration.

In the panel, every treated market first meets the condition in one of four quarters: 2020Q3 (84 markets), 2021Q1 (97), 2021Q3 (73) and 2022Q1 (52). The remaining 294 markets are never treated. The cohort structure is reported in Table 4.2 and the number of markets in each cohort is shown in Figure 4.1. Among treated markets, the cumulative net inflow of remote-capable workers reached an average of 1.9 percent of base employment by eight quarters after the treatment date; this is the inflow measure used to scale the employment effect into the local multiplier in Chapter 5.

## A.4 Outcome variables

All outcomes are measured quarterly at the market level and entered in natural logarithms. Table A.1 summarizes their definitions.

**Table A.1** Definitions of the main variables

| Variable | Definition | Unit |
|---|---|---|
| Non-tradable employment | Employment in retail trade, food service, personal services and construction trades | Jobs, log |
| Tradable employment | Employment in industries producing goods or services sold mainly outside the market | Jobs, log |
| Non-tradable weekly wage | Total quarterly wage bill in non-tradable industries divided by non-tradable employment and by 13 | Dollars, log |
| Median asking rent | Median monthly asking rent for listed rental units in the quarter | Dollars, log |
| House price index | Repeat-sales style index of transaction prices, 2019 mean normalized to 100 | Index, log |
| Base employment $E^{2019}_c$ | Mean total employment over 2019Q1–2019Q4 | Jobs |
| Scaled net inflow $m_{ct}$ | Seasonally adjusted net in-migration of remote-capable workers divided by $E^{2019}_c$ | Share |
| Treatment date $G_c$ | First quarter of two consecutive quarters with $m_{ct} > 0.005$ | Quarter |

*Note.* All variables are from the synthetic panel described in Chapter 4. Industries classified as neither tradable nor non-tradable are excluded from both employment aggregates.

### A.4.1 Non-tradable and tradable employment

The classification of industries follows the logic of the local-multiplier literature, in which non-tradable industries are those whose output must be consumed near the place where it is produced {{moretti2010}}. The non-tradable aggregate includes retail trade, food service, personal services such as hair care and repair, and the construction trades. Construction is included because residential repair and renovation are delivered on site and respond to local demand, and because the arrival of new residents raises demand for such work directly. Tradable employment covers industries whose output is sold mainly outside the market. Industries whose classification is ambiguous, such as public administration and some health and education services, are excluded from both aggregates. The tradable aggregate is used as a check in Chapter 6: under assumption (A3) of the model, as restated in Appendix C, tradable employment should not respond to first order.

### A.4.2 Wages

The non-tradable wage is the mean weekly wage in non-tradable industries, computed as the total quarterly wage bill divided by employment and by the thirteen weeks of a quarter. This is a mean, so it can change both because the wages of incumbent workers change and because the composition of the workforce changes. Chapter 6 discusses this distinction. The measure is not deflated, because the comparison in every specification is between markets in the same quarter, and a common national price index would be absorbed by the quarter fixed effects or by the construction of the group-time comparisons.

### A.4.3 Rents and house prices

Rents are measured by the median asking rent for listed units in each market and quarter. Asking rents respond more quickly to demand than rents on existing leases, so they are the natural measure for a study of short-run responses, but they overstate the speed at which the rents paid by sitting tenants adjust. The house price index is constructed from repeated transactions of the same properties, so that it is not affected by changes in the mix of properties sold. Both are normalized and logged so that estimated effects can be read as approximate percentage changes.

## A.5 Consistency checks

Before estimation, the panel was subjected to four checks. First, the balance check confirmed that all 600 markets are present in all 32 quarters for every variable, so that no observation is dropped by any estimator and all tables report 19,200 observations. Second, an accounting check confirmed that non-tradable and tradable employment, together with the excluded industries, sum to total employment in every market-quarter. Third, a flow check confirmed that gross inflows across all markets equal gross outflows in each quarter, as they must in a closed system of markets, up to a residual for moves into and out of the system as a whole. Fourth, the treatment coding was re-run independently from the raw flows and produced the same treatment date for every market.

Table A.2 summarizes the construction of the estimation sample.

**Table A.2** Construction of the estimation sample

| Step | Markets | Market-quarters |
|---|---|---|
| Labor markets with fixed boundaries, 2017Q1–2024Q4 | 600 | 19,200 |
| Markets meeting the treatment condition, any quarter | 306 | 9,792 |
| Of which: first treated 2020Q3 | 84 | 2,688 |
| Of which: first treated 2021Q1 | 97 | 3,104 |
| Of which: first treated 2021Q3 | 73 | 2,336 |
| Of which: first treated 2022Q1 | 52 | 1,664 |
| Never-treated markets | 294 | 9,408 |
| Estimation sample | 600 | 19,200 |

*Note.* Market-quarters count all 32 quarters for each market, including pre-treatment quarters. Treatment condition as in Equation (A.3).

The panel thus offers what the estimators of Chapter 4 require: a balanced panel with a long pre-period, a well-defined treatment date for each treated market, a large never-treated group, and outcomes measured consistently across markets and over time.
