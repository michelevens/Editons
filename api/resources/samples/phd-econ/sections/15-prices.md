# Chapter 6: Wages, Rents and the Incidence of Remote-Worker Arrivals

## 6.1 Introduction

Chapter 5 established the quantity side of the story. When a local labor market crosses the treatment threshold defined in Chapter 4, non-tradable service employment rises by about 2.1 percent on average, the effect builds over two years rather than arriving at once, and each additional remote worker is associated with roughly 0.6 additional local jobs in retail, food service, personal services and the construction trades. Those findings answer the first half of the dissertation's question. They do not answer the second half, which is the one that matters for most of the people who already live in a treated market: who gains? A rise in local employment is a statement about the number of jobs. Whether incumbent residents are better or worse off depends on what happens to the prices they face, and above all on the two prices that the spatial-equilibrium tradition puts at the center of local welfare: the wage and the rent {{moretti2010;moretti2012}}.

This chapter turns to those prices. It asks three questions. First, how do wages in non-tradable services, residential rents and house prices respond when remote-capable workers arrive with incomes earned elsewhere? Second, do these responses vary across markets in the way the model of Chapter 3 says they should, in particular with the elasticity of local housing supply and with local amenities? Third, once the wage and rent effects are combined, which incumbent households come out ahead and which fall behind?

The answers can be stated briefly before the evidence is laid out. Non-tradable wages rise by about 1.2 percent, median asking rents by about 4.6 percent and house prices by about 6.3 percent, while tradable employment, which serves as a near-placebo outcome, does not move. The rent response grows steadily over the first eight quarters after treatment and shows no sign of anticipation in the quarters before it. The rent response is more than twice as large in markets where housing supply is inelastic, and in those same markets the employment response is smaller, which is the joint pattern that Propositions 3.1 and 3.2 predict. Combining the wage and rent estimates in a simple incidence calculation, a renter household that works in non-tradable services loses on net whenever rent absorbs more than about 26 percent of its income, while incumbent homeowners gain through both the wage and the appreciation of their property. Averaged over incumbent residents, the arrival of remote workers is positive for local welfare, because newcomers pay rent to local owners, but the gains are distributed regressively.

The chapter proceeds as follows. Section 6.2 restates the parts of the model that bear on prices and turns them into testable predictions. Section 6.3 describes the outcome variables and the estimation, which follow the design of Chapter 4 closely. Section 6.4 reports the main estimates for wages, rents, house prices and tradable employment, and Section 6.5 examines the dynamics of rents in an event-study framework. Section 6.6 presents heterogeneity by housing-supply elasticity and by amenities and connects it to the model. Section 6.7 develops the incidence calculation. Section 6.8 discusses threats to interpretation specific to price outcomes, and Section 6.9 summarizes and hands on to the concluding chapter.

## 6.2 What the Model Predicts for Prices

### 6.2.1 The equilibrium logic

The model of Chapter 3 is a Rosen–Roback spatial equilibrium extended to a third type of worker. Local tradable workers produce goods sold on national markets; non-tradable workers produce services consumed locally; and remote workers draw a wage $w^R$ that is set nationally and does not depend on where they live. All three types have indirect utility of the form

$$
U_{jc} = \ln w_{jc} - \alpha \ln r_c + A_c \tag{?}
$$

where $w_{jc}$ is the wage of type $j$ in market $c$, $r_c$ is the local rent, $\alpha$ is the expenditure share of housing and $A_c$ is a local amenity. Mobile workers sort until utility is equalized across the markets in which they locate. Demand for non-tradable services is proportional to local income, so any increase in the local income base raises the demand for local labor in services. Housing is supplied with an elasticity $\eta_c$ that varies across markets because of land, topography and regulation.

The arrival of remote workers shifts this system in a specific way. Because $w^R$ is fixed nationally, an increase in the number of remote residents, which I write $R_c$, raises local income without raising local tradable output. It is, in the language of the local-multiplier literature, an increase in the export base that does not require any local firm to sell more abroad {{moretti2010}}. The extra income raises demand for non-tradable services and for housing at the same time. The demand for services raises employment and, to the extent that the local supply of non-tradable labor is less than perfectly elastic, the non-tradable wage. The demand for housing raises the rent, by an amount that depends on $\eta_c$.

The rent increase then feeds back into the labor market. Workers who move into a market to take the new service jobs must be compensated for the higher cost of living, so the wage required to attract a marginal non-tradable worker rises with the rent. When housing supply is inelastic, a given inflow of remote income produces a larger rent increase, a larger compensating wage increase, and therefore a smaller expansion of non-tradable employment. The quantity and price margins are linked: what housing scarcity takes in the form of rents, it gives back in the form of fewer jobs.

### 6.2.2 Comparative statics

Linearizing the equilibrium around a baseline with no remote workers, Chapter 3 derives elasticities of local outcomes with respect to the remote-worker stock. The two that matter most here can be written in reduced form as

$$
\frac{d \ln r_c}{d \ln R_c} = \frac{\phi_c}{\eta_c + \psi_c} \tag{?}
$$

and

$$
\frac{d \ln w^{NT}_c}{d \ln R_c} = \alpha \, \frac{d \ln r_c}{d \ln R_c} + \frac{1}{\varepsilon^{NT}} \, \frac{d \ln N^{NT}_c}{d \ln R_c} \tag{?}
$$

where $\phi_c > 0$ collects the income share of remote workers and the housing share of their spending, $\psi_c > 0$ captures how strongly housing demand responds to rents through migration, and $\varepsilon^{NT}$ is the elasticity of local non-tradable labor supply. Equation (6.2) makes the rent response decreasing in $\eta_c$, or equivalently increasing in $1/\eta_c$. Equation (6.3) decomposes the wage response into a compensating-differential component, which scales with the rent response and the housing share, and a labor-demand component, which scales with the employment response and the inverse of the labor-supply elasticity. The notation here is a compressed version of the derivation in Chapter 3; the full system, and the definitions of $\phi_c$ and $\psi_c$ in terms of primitives, are given there.

Propositions 3.1 and 3.2 sign these responses. Proposition 3.1 states that non-tradable employment rises with the remote-worker stock and that the response is smaller where housing supply is less elastic. Proposition 3.2 states that the rent response is positive and increasing in $1/\eta_c$. Proposition 3.3 concerns incidence: it shows that, for incumbent residents, the welfare effect of remote arrivals depends on tenure, because owners receive the rent increase that renters pay, and that the effect for a renter is negative whenever the housing share exceeds the ratio of the wage response to the rent response. The proofs are in Appendix C.

### 6.2.3 Testable predictions

Taken together, the model delivers five predictions about prices that the panel can confront. I state them here so that the evidence that follows can be read against them rather than interpreted after the fact.

1. Rents rise in treated markets, and the rise is larger than the rise in non-tradable wages, because remote workers add to housing demand directly while adding to labor demand only through their spending.
2. House prices rise at least as much as rents, because the asset value of housing capitalizes the expected path of future rents, and if rent growth is expected to continue the price response should exceed the contemporaneous rent response.
3. Tradable employment does not respond, because tradable output is priced nationally and remote arrivals do not change local productivity in the baseline model.
4. Across markets, the rent response is larger and the non-tradable employment response smaller where housing supply is inelastic. This is the joint content of Propositions 3.1 and 3.2, and it is the sharpest test of the mechanism because it involves opposite movements in two outcomes in response to a single market characteristic.
5. The incidence on incumbent renters is negative when their housing share is high relative to the ratio of wage to rent responses, and positive otherwise (Proposition 3.3).

Prediction 3 deserves a qualification. The baseline model abstracts from agglomeration economies in the tradable sector. If remote workers bring knowledge, networks or demand for specialized local inputs, they could raise tradable productivity through the sharing, matching and learning mechanisms that the micro-foundations literature identifies {{duranton2004}}. The empirical evidence on agglomeration suggests that such effects are real but attenuate with distance and depend on industrial proximity {{rosenthal2004}}, and that much of the productivity advantage of dense places reflects agglomeration rather than the selection of more productive firms {{combes2012}}. Remote workers employed by firms elsewhere are, almost by definition, weakly connected to local tradable producers, and the learning channel through which cities raise wages over time {{glaeser2001}} operates mainly through workplace interaction. I therefore expect tradable effects to be small within the eight-quarter horizon examined here, but a null on tradable employment is a prediction of the baseline model rather than an identity.

## 6.3 Outcomes and Estimation

### 6.3.1 Price outcomes

The four outcomes in this chapter are defined in Chapter 4 and are summarized here only to fix interpretation. The non-tradable wage is the log of mean weekly earnings of workers employed in the four non-tradable industry groups, measured at the market-quarter level. Because it is a mean over employed workers, it moves with both the wage paid for a given job and the composition of jobs; Section 6.8 discusses what this implies. The rent outcome is the log of the median asking rent for residential units listed in the market during the quarter. Asking rents measure the price faced by a household searching for housing, which is the relevant price for movers, including arriving remote workers and workers relocating to take new service jobs. They respond faster than contract rents on existing leases, a feature that matters for the incidence calculation and is taken up in Section 6.7. The house price outcome is the log of a repeat-sales house price index, normalized to the market's 2019 average. Tradable employment is the log of employment in goods-producing and other tradable industries, defined as in Chapter 4.

All four outcomes are observed for every market in every quarter of the panel, so each estimation sample contains 19,200 market-quarters from 600 markets. The treatment definition, the cohort structure and the control groups are unchanged from Chapter 5. Treatment begins in the first quarter in which a market's net in-migration of remote-capable workers exceeds 0.5 percent of its pre-2020 employment for two consecutive quarters, with remote capability assigned at the occupation level following {{dingel2020|n}}. Four cohorts are treated, in 2020Q3, 2021Q1, 2021Q3 and 2022Q1, comprising 84, 97, 73 and 52 markets respectively, and 294 markets are never treated.

### 6.3.2 Estimators

The headline estimates use the group-time average treatment effects of {{callaway2021|n}}, defined in Equation (4.3), aggregated across groups and periods using the weights in Equation (4.4). The comparison group combines never-treated markets with markets not yet treated at the relevant date, exactly as in Chapter 5. This choice uses the full set of clean comparisons available in a staggered design and avoids the comparisons of later-treated markets with already-treated ones that contaminate two-way fixed-effects estimates when effects evolve over time {{goodmanbacon2021}}.

Alongside the Callaway–Sant'Anna estimates I report three others. The interaction-weighted estimator of {{sun2021|n}} uses only never-treated markets as controls and recovers convex averages of cohort-specific effects. The imputation estimator of {{borusyak2024|n}} fits market and quarter effects on untreated observations, imputes the counterfactual outcome for each treated observation and averages the differences; under homoskedasticity it is the efficient estimator in this class. Finally, I report the static two-way fixed-effects estimate from Equation (4.1), not because it is credible in this setting but because the gap between it and the robust estimates is itself informative. Chapter 5 showed that for non-tradable employment 38 percent of the TWFE weight falls on forbidden comparisons and 21 percent of the de Chaisemartin–D'Haultfœuille weights are negative {{dechaisemartin2020}}. The weights depend only on the treatment timing and the fixed effects, not on the outcome, so the same weights apply to every outcome in this chapter. What differs across outcomes is how strongly the effects grow with time since treatment, and therefore how much bias those weights produce.

Event-study estimates for rents follow Equation (4.2) in its Callaway–Sant'Anna form: group-time effects are aggregated by event time $e$, with $e = -1$ as the reference period. Pre-treatment coefficients are computed with the varying base period, so each compares adjacent quarters, which makes them interpretable as tests of parallel pre-trends rather than as cumulative differences. The recommendations synthesized by {{roth2023|n}} guide these choices, in particular the advice to report pre-trend tests alongside sensitivity analysis rather than treating an insignificant pre-trend test as proof of parallel trends.

### 6.3.3 Inference

Standard errors are clustered at the market level throughout. The argument for clustering at this level is a design argument rather than a residual-correlation argument: treatment is assigned to whole markets, so the market is the unit at which assignment is clustered {{abadie2023}}. Clustering by market also accommodates arbitrary serial correlation within a market over the 32 quarters of the panel, which is the specific failure that leads conventional difference-in-differences standard errors to overstate precision {{bertrand2004}}. With 600 clusters, of which 306 are treated, asymptotic cluster-robust inference is reliable for the aggregate estimates. For the heterogeneity splits and the cohort-specific estimates, where the effective number of treated clusters falls to between 52 and 153, I also report wild cluster bootstrap p-values, following the practical guidance in {{cameron2015|n}}. They do not change any conclusion. Throughout, one, two and three asterisks denote significance at the 10, 5 and 1 percent levels.

## 6.4 Main Estimates

### 6.4.1 Overview

Table 6.1 reports the main estimates for the four price and placebo outcomes. Each column is a separate outcome; each row is an estimator. Coefficients are multiplied by 100 so that they can be read as approximate percentage effects.

**Table 6.1** Wages, rents, house prices and tradable employment

| Estimator | Log NT weekly wage | Log median asking rent | Log house price index | Log tradable employment |
|---|---|---|---|---|
| Callaway–Sant'Anna (overall ATT) | 1.2** (0.5) | 4.6*** (1.1) | 6.3*** (1.5) | 0.2 (0.4) |
| Sun–Abraham (interaction-weighted) | 1.1** (0.5) | 4.4*** (1.1) | 6.0*** (1.5) | 0.1 (0.4) |
| Borusyak–Jaravel–Spiess (imputation) | 1.3*** (0.4) | 4.8*** (1.0) | 6.5*** (1.4) | 0.2 (0.3) |
| Static TWFE | 0.7* (0.4) | 3.1*** (0.9) | 4.2*** (1.3) | 0.1 (0.3) |
| Pre-trend joint test, p-value (CS) | 0.55 | 0.71 | 0.49 | 0.80 |
| Observations (market-quarters) | 19,200 | 19,200 | 19,200 | 19,200 |
| Markets | 600 | 600 | 600 | 600 |
| Treated markets | 306 | 306 | 306 | 306 |

*Note.* Coefficients are multiplied by 100. Standard errors, clustered by market, in parentheses. The Callaway–Sant'Anna estimates use never-treated and not-yet-treated markets as controls; Sun–Abraham uses never-treated markets only. The pre-trend test is a joint Wald test that all pre-treatment event-study coefficients for $e = -8, \dots, -2$ are zero. * p < 0.10, ** p < 0.05, *** p < 0.01.

Three features of the table stand out. The robust estimators agree closely with one another for every outcome, with differences between them that are small relative to their standard errors. The static TWFE estimates are uniformly smaller, by between a third and a half. And the placebo outcome is flat across all four estimators. I take these in turn, beginning with wages.

### 6.4.2 Non-tradable wages

The Callaway–Sant'Anna estimate implies that mean weekly earnings in non-tradable services rise by about 1.2 percent in treated markets relative to the counterfactual path. The Sun–Abraham and imputation estimates bracket this at 1.1 and 1.3 percent. The effect is statistically distinguishable from zero at conventional levels, but it is modest: about four-sevenths of the 2.1 percent employment effect reported in Chapter 5.

The relative size of the wage and employment effects is informative about the slope of local labor supply. If the wage and employment responses were both driven by a shift in non-tradable labor demand along a stable supply curve, the ratio of the employment response to the wage response would estimate the local supply elasticity, here roughly $2.1 / 1.2 \approx 1.75$. That figure should not be read as a structural parameter. Equation (6.3) shows why: part of the wage response is a compensating differential for higher rents rather than a movement along the supply curve. With the rent response of 4.6 percent and a housing share in the range the model takes as baseline, the compensating-differential component alone could account for a substantial fraction of the wage gain, which would imply that the residual movement along the labor-supply curve is small and the supply elasticity correspondingly large. The data are consistent with a fairly elastic local supply of non-tradable labor, drawn from commuters, labor-force entrants and workers reallocating from other sectors, which is the setting in which the local-multiplier literature expects quantity effects to dominate price effects {{moretti2010}}.

This interpretation fits a broader point about who benefits from high-income arrivals. {{moretti2012|n}} argues that high-skill employment in a city raises the wages of less-skilled workers in the same city through local demand for their services. The remote-worker shock studied here is a cleaner version of that mechanism than most, because remote arrivals add local income without adding local high-skill employment that might compete with or complement incumbents in production. The wage evidence suggests that the spending channel does raise service wages, but by less than the rent channel raises housing costs. That comparison is the subject of Section 6.7.

### 6.4.3 Rents and house prices

Median asking rents rise by 4.6 percent under the Callaway–Sant'Anna estimator, with the other robust estimators giving 4.4 and 4.8 percent. House prices rise by 6.3 percent, with a range of 6.0 to 6.5 percent. Both effects are precisely estimated relative to their size, and both are economically large compared with the wage response. The rent effect is nearly four times the wage effect, which is the ordering that Prediction 1 anticipates. Remote workers are, for the local housing market, simply additional households with incomes that do not depend on local conditions; for the local labor market they are only indirect sources of demand.

The house price response exceeds the rent response by about 1.7 log points. In a user-cost framework, the price of a house equals the present value of expected future rents, so a price response larger than the contemporaneous rent response means that buyers expect rents to keep rising, that the discount rate applied to treated-market housing has fallen, or both. The event-study evidence in Section 6.5 shows that rents do continue to rise for at least eight quarters after treatment, so an expectation of further rent growth is consistent with what subsequently happened. I cannot separate expectations from discount-rate effects with the data available, and the incidence calculation therefore treats the house price response as a measure of capital gains to incumbent owners without decomposing it.

These findings are naturally compared with the evidence on how the pandemic shift to remote work reshaped real estate within metropolitan areas. {{gupta2022|n}} document that house prices and rents fell in city centers and rose farther out, flattening the bid-rent curve in large metros, and that the flattening was more pronounced where working from home was more prevalent and where housing supply was less elastic. {{ramani2021|n}} describe the same reallocation of population, business activity and real estate demand from dense centers toward suburbs, and attribute much of it to hybrid work that keeps workers within commuting distance of their offices. The present chapter studies a different margin: moves between local labor markets rather than within them. The two margins are complementary. Within-metro reallocation redistributes housing demand along the commuting gradient of a single labor market, while between-market relocation, which requires fully or mostly remote work, shifts housing demand across labor markets. That the rent response here is sizable suggests that the between-market margin has price consequences of the same qualitative kind as those documented within metros, although the synthetic panel cannot speak to their relative magnitudes in the real economy.

### 6.4.4 Tradable employment

The tradable employment estimates are close to zero under every estimator, at 0.2 percent with a standard error of 0.4 under Callaway–Sant'Anna. The 95 percent confidence interval excludes effects larger than about 1 percent in either direction. This outcome plays two roles. Substantively, it is Prediction 3 of the model: tradable output is priced nationally and remote arrivals do not, in the baseline model, change local productivity, so tradable employment should not respond. Methodologically, it is a near-placebo. The main identification concern for this chapter, as for Chapter 5, is that markets crossing the remote-inflow threshold were already on a different trajectory, perhaps because of a broader local boom that would have raised employment and prices anyway. A general boom would plausibly raise tradable as well as non-tradable employment. The absence of any tradable response therefore makes a general-boom explanation of the price effects less plausible, although it does not rule out a boom confined to the housing market or the service sector.

I describe this outcome as a near-placebo rather than a placebo because the model permits small tradable effects through two channels. Rising wages and rents raise production costs for local tradable firms, which could reduce tradable employment at the margin, while agglomeration effects could raise it. The null is consistent with these channels being small or offsetting over the horizon studied.

### 6.4.5 The gap between TWFE and robust estimates

The static TWFE estimates in the fourth row of Table 6.1 are smaller than the robust estimates for every outcome with a nonzero effect: 0.7 against 1.2 for wages, 3.1 against 4.6 for rents and 4.2 against 6.3 for house prices. In proportional terms the TWFE estimate captures between 58 and 67 percent of the Callaway–Sant'Anna estimate, which is close to the corresponding ratio for non-tradable employment in Chapter 5 (1.3 against 2.1, or 62 percent).

The source of the gap is the one diagnosed in Chapter 5. The decomposition of {{goodmanbacon2021|n}} writes the TWFE estimate as a variance-weighted average of all two-group, two-period comparisons, and in this panel 38 percent of the weight falls on comparisons in which earlier-treated markets serve as controls for later-treated ones. When effects grow over time, as they do for every outcome here, the earlier-treated markets are themselves on a rising path during the later markets' treatment window, so these comparisons subtract part of the treatment effect from itself. The bias is larger for outcomes whose effects grow more steeply. Rents and house prices, which rise more steeply after treatment than wages do, have TWFE estimates biased downward by more in absolute terms, as the table shows. None of the TWFE estimates has the wrong sign, but the general lesson that such estimates can mislead in staggered designs with dynamic effects {{dechaisemartin2020;sun2021}} applies with full force. A researcher relying on TWFE alone would understate the rent effect of remote arrivals by about one-third and would correspondingly understate the cost to renters.

## 6.5 Rent Dynamics

### 6.5.1 Event-study estimates

Figure 6.1 plots the Callaway–Sant'Anna event-study estimates for log median asking rent, from eight quarters before treatment to eight quarters after, with pointwise 95 percent confidence intervals.

**Figure 6.1** Event-study estimates for log median rent

```chart
{"type": "line", "title": "Event-study estimates for log median asking rent (percent)", "xLabel": "Quarters relative to treatment (e)", "yLabel": "Effect on log median rent (x 100)", "data": "e,Estimate,Lower 95%,Upper 95%\n-8,0.3,-0.88,1.48\n-7,-0.2,-1.38,0.98\n-6,0.1,-0.88,1.08\n-5,-0.3,-1.28,0.68\n-4,0.2,-0.78,1.18\n-3,-0.1,-0.88,0.68\n-2,0.2,-0.58,0.98\n-1,0,0,0\n0,0.9,-0.08,1.88\n1,1.7,0.52,2.88\n2,2.6,1.23,3.97\n3,3.4,1.83,4.97\n4,4.1,2.34,5.86\n5,4.9,2.94,6.86\n6,5.6,3.44,7.76\n7,6.1,3.75,8.45\n8,6.5,3.95,9.05"}
```

*Note.* Callaway–Sant'Anna estimates aggregated by event time, with never-treated and not-yet-treated controls; $e = -1$ is the reference period. Confidence intervals use standard errors clustered by market. Observations: 19,200 market-quarters; 600 markets.

The pre-treatment coefficients lie between −0.3 and +0.3 percent, none is individually significant, and the joint test that all seven are zero has a p-value of 0.71. There is no visible drift in the quarters immediately before treatment, which is where anticipation would appear if remote workers were drawn to markets in which rents were already beginning to rise, or if landlords raised asking rents in advance of an expected inflow. The absence of a pre-treatment rise is particularly reassuring for rents because asking rents are forward-looking: a landlord who observes early signs of rising demand can reprice a vacant unit immediately. That the series is flat until the quarter of treatment suggests that the treatment definition, which requires two consecutive quarters above the inflow threshold, is not systematically lagging behind the onset of the housing demand shock.

After treatment, the rent effect rises almost linearly. It is 0.9 percent in the quarter of treatment and not yet significant, 1.7 percent one quarter later, 4.1 percent at $e = 4$ and 6.5 percent at $e = 8$. The confidence intervals widen with event time, from about ±1.0 at $e = 0$ to about ±2.6 at $e = 8$, because fewer cohorts contribute to the longer horizons and because rent shocks accumulate. The overall ATT of 4.6 percent in Table 6.1 exceeds the simple average of the event-time coefficients from $e = 0$ to $e = 8$, which is about 4.0 percent. The difference arises because the overall aggregation in Equation (4.4) weights all post-treatment group-time cells, including those more than eight quarters after treatment for the 2020Q3 and 2021Q1 cohorts, at which rents are higher still.

### 6.5.2 Comparing the paths of rents and employment

The rent path in Figure 6.1 rises more steeply and for longer than the employment path in Figure 5.1. Non-tradable employment reaches 1.9 percent at $e = 4$ and 3.0 percent at $e = 8$; rents reach 4.1 and 6.5 percent at the same horizons. The ratio of the rent effect to the employment effect is roughly constant at a little over two from $e = 2$ onward. Two features of the model help interpret this.

First, both outcomes track the stock of remote workers rather than the flow. Remote inflows continue after the treatment date, reaching an average of 1.9 percent of baseline employment by eight quarters after treatment, so the shock to local income and housing demand itself grows over the event window. Steady growth in both outcomes is what the model predicts when the forcing variable grows steadily.

Second, the relative steepness of the rent path is consistent with housing supply being less elastic in the short run than labor supply. Equation (6.2) implies that, holding the remote shock fixed, the rent response is governed by $\eta_c$. A back-of-envelope calculation gives a sense of magnitude. At $e = 8$ the remote inflow is 1.9 percent of baseline employment and the rent effect is 6.5 percent. If housing demand rose only with remote arrivals, the implied short-run inverse supply elasticity would be about $6.5 / 1.9 \approx 3.4$. If the induced non-tradable jobs, at 0.6 per remote worker, were all filled by new residents who also demand housing, the demand shift would be about $1.9 \times 1.6 \approx 3.0$ percent and the implied inverse elasticity about 2.1. These bounds place the average short-run housing supply elasticity in treated markets between roughly 0.3 and 0.5. Such low values are expected over a two-year horizon in which new construction can respond only partially, and they are averages over markets with very different supply conditions, a point Section 6.6 takes up directly. The calculation ignores changes in household size and in the housing consumption of incumbents, so it is indicative rather than structural.

### 6.5.3 Sensitivity to violations of parallel trends

Flat pre-trends are necessary but not sufficient for credible identification. The pre-trend test may have low power against violations that would matter, and conditioning on passing it can itself distort inference. The approach of {{rambachan2023|n}} addresses this by asking how large post-treatment violations of parallel trends would have to be, relative to the largest violation observed before treatment, to overturn a conclusion. I apply their relative-magnitudes restriction to the rent effect at $e = 4$, the same horizon used for the employment sensitivity analysis in Chapter 5.

The rent effect at $e = 4$ remains significantly positive for values of $\bar{M}$ up to 1.1. In words, the post-treatment departure from parallel trends would have to be more than 10 percent larger than the largest quarter-to-quarter pre-treatment departure, sustained in every quarter up to $e = 4$ and all in the direction of inflating the estimate, before the robust confidence set would include zero. The corresponding breakdown value for non-tradable employment, reported in Chapter 5, is $\bar{M} = 0.8$. The rent result is somewhat more robust because the rent effect is larger relative to the noise in the pre-treatment coefficients. At $e = 8$ the breakdown value is lower, at about 0.7, because the bounds widen mechanically with the number of post-treatment periods over which violations can accumulate. Neither value is large in absolute terms, and I read the evidence as establishing the sign and approximate magnitude of the rent effect over the first year with reasonable confidence and its magnitude at two years with less.

## 6.6 Heterogeneity: Housing Supply and Amenities

### 6.6.1 Why heterogeneity is the sharpest test

The average effects in Table 6.1 are consistent with the model, but they are also consistent with simpler stories. Any positive local demand shock raises rents; any shock that raises service employment may raise service wages. The predictions that are distinctive to the spatial-equilibrium mechanism concern how effects vary across markets. Propositions 3.1 and 3.2 make a joint prediction about housing-supply elasticity that has an unusual structure: where housing supply is inelastic, the rent response should be larger and the employment response smaller. A confounding shock that simply made some markets more responsive across the board would raise both responses together. A pattern in which they move in opposite directions across the same split is harder to produce without the housing-cost channel the model emphasizes.

The amenity split tests a different implication. In the model, amenities $A_c$ enter utility additively, so amenity-rich markets attract remote workers at a given rent. Conditional on treatment, the composition of arrivals and the scale of the inflow may differ, and amenity-rich markets may have a larger subsequent inflow. The model makes no sharp prediction about price heterogeneity by amenities, but the split is informative about where remote arrivals generate the most local employment.

### 6.6.2 Construction of the splits

Housing-supply elasticity is measured by a synthetic market-level index constructed for the panel, which combines a land-availability component and a regulatory-stringency component into a single score. I split markets at the median of this index computed over all 600 markets, so that each half contains 300 markets; among treated markets, the split yields 153 inelastic and 153 elastic markets. The amenity split uses terciles of the synthetic amenity index, again defined over all markets. Treated markets are over-represented in the top amenity tercile, which contains 120 treated markets, against 104 in the middle and 82 in the bottom tercile. That imbalance is itself consistent with the role of amenities in the location choices of workers who are no longer tied to an employer's location.

Each subgroup estimate is a Callaway–Sant'Anna overall ATT computed within the subgroup, so treated markets in a subgroup are compared only with never-treated and not-yet-treated markets in the same subgroup. This within-subgroup comparison is important. A pooled regression with an interaction term would compare treated inelastic markets in part with control elastic markets, and would therefore confound the treatment-effect heterogeneity of interest with differences in untreated trends between the two types of market.

### 6.6.3 Results

Table 6.2 reports the results.

**Table 6.2** Heterogeneity by housing-supply elasticity and amenities

| Subgroup | Treated markets | Log NT employment | Log NT weekly wage | Log median asking rent | Log house price index |
|---|---|---|---|---|---|
| *Housing-supply elasticity* | | | | | |
| Inelastic (below median) | 153 | 1.6** (0.7) | 1.6** (0.7) | 6.8*** (1.6) | 8.9*** (2.1) |
| Elastic (above median) | 153 | 2.7*** (0.8) | 0.9 (0.6) | 2.9** (1.2) | 3.8** (1.8) |
| Difference (inelastic − elastic) | | −1.1 (1.1) | 0.7 (0.9) | 3.9** (2.0) | 5.1* (2.8) |
| *Amenity index* | | | | | |
| Top tercile | 120 | 2.9*** (0.9) | 1.0 (0.7) | 5.9*** (1.8) | 7.6*** (2.4) |
| Middle tercile | 104 | 1.9** (0.8) | 1.2* (0.7) | 4.3** (1.7) | 6.0*** (2.2) |
| Bottom tercile | 82 | 1.4 (0.9) | 1.4* (0.8) | 3.4* (1.8) | 5.1** (2.3) |
| Observations (market-quarters) | | 19,200 | 19,200 | 19,200 | 19,200 |
| Markets | | 600 | 600 | 600 | 600 |

*Note.* Coefficients are multiplied by 100. Each entry is a Callaway–Sant'Anna overall ATT estimated within the subgroup, using never-treated and not-yet-treated markets in the same subgroup as controls. Standard errors, clustered by market, in parentheses; wild cluster bootstrap p-values (999 replications) lead to the same significance classifications. Differences are estimated by stacking the two subgroup estimators and computing a cluster-robust variance. * p < 0.10, ** p < 0.05, *** p < 0.01.

The housing-supply split delivers the pattern predicted by Propositions 3.1 and 3.2. In inelastic markets rents rise by 6.8 percent, against 2.9 percent in elastic markets, a difference of 3.9 percentage points that is significant at the 5 percent level. House prices diverge even more, at 8.9 against 3.8 percent. Non-tradable employment moves in the opposite direction: 1.6 percent in inelastic markets against 2.7 percent in elastic ones. The employment difference of −1.1 points has the predicted sign but is imprecisely estimated, with a standard error of 1.1, and I do not claim that it is statistically established on its own. What carries the evidential weight is the conjunction. The model predicts a specific trade-off across markets between price and quantity responses, and the point estimates line up with it on all four outcomes. Non-tradable wages, which in Equation (6.3) contain a compensating-differential component proportional to the rent response, rise more in inelastic markets as well, at 1.6 against 0.9 percent.

One way to summarize the split is through the ratio of the rent response to the employment response, which in the model is increasing in $1/\eta_c$. In inelastic markets the ratio is about $6.8 / 1.6 \approx 4.3$; in elastic markets it is about $2.9 / 2.7 \approx 1.1$. The same remote income shock that, in an elastic market, translates into roughly equal proportional increases in service jobs and rents translates, in an inelastic market, into a rent increase four times the employment increase. This is the sense in which housing scarcity converts a local demand shock from a jobs story into a rents story.

These results connect to the within-metro evidence in {{gupta2022|n}}, who find that the flattening of the bid-rent curve after the onset of remote work was greater where housing supply was less elastic. The mechanism is the same in both settings: when housing cannot expand to accommodate new demand, the demand is capitalized into prices. They also echo the simulation results of {{delventhal2022|n}}, whose quantitative model of a single metropolitan area predicts that a permanent rise in telecommuting moves residents toward the periphery and raises peripheral real estate prices relative to the core. That model and the present design differ in scale and identification, but both point to housing supply as the margin that determines how much of a residential reallocation shows up in prices.

The amenity split tells a different story. Employment effects are largest in the top amenity tercile, at 2.9 percent, and fall to 1.9 and 1.4 percent in the middle and bottom terciles. Rent effects follow the same ordering, from 5.9 to 3.4 percent, while wage effects are, if anything, slightly smaller in amenity-rich markets. The model offers an interpretation. Amenity-rich markets attract larger subsequent inflows after the treatment threshold is crossed, so the effective shock is larger there; this raises both employment and rents. The wage pattern is consistent with the compensating-differential logic in a different form: workers accept lower wage gains in places that are pleasant to live in, so a given increase in labor demand is met with less wage pressure. I treat this interpretation with caution because the amenity index is correlated with other market characteristics, including housing-supply elasticity, and the tercile estimates are imprecise. The difference between top and bottom terciles in the employment effect, 1.5 percentage points, is not significant at conventional levels.

### 6.6.4 What the heterogeneity does and does not establish

The heterogeneity results support the model's central mechanism without proving it. A skeptic could argue that housing-supply elasticity proxies for other characteristics, such as initial density, that independently affect how markets respond to inflows. The synthetic elasticity index is constructed from land and regulatory components that are fixed before the sample period, which removes the concern that it responds to treatment, but it does not remove all scope for confounding by correlated characteristics. A natural extension, discussed in Chapter 7, is to estimate the heterogeneity within strata of baseline density or size, which would require more markets than the panel provides to retain useful precision.

It is also worth stressing what the elasticity split implies for the multiplier estimated in Chapter 5. The average multiplier of 0.6 non-tradable jobs per remote worker conceals substantial variation. If the inflow per treated market is similar across the elasticity split, the multiplier in elastic markets is roughly 70 percent larger than in inelastic ones. The local job-creation benefit of remote arrivals is therefore concentrated in precisely the markets where housing can expand, while the housing-cost burden is concentrated where it cannot.

## 6.7 The Incidence of Remote-Worker Arrivals

### 6.7.1 From prices to welfare

The estimates so far describe how prices move. The question that motivates the dissertation is who gains, and answering it requires combining the price movements with the circumstances of different households. This section develops a back-of-envelope incidence calculation in the spirit of the spatial-equilibrium literature, which evaluates local shocks by their effect on the real incomes of residents rather than on employment counts alone {{moretti2012}}. The calculation is deliberately simple. It takes the estimated wage, rent and house price effects as given, applies them to stylized incumbent households that differ in sector and housing tenure, and asks how their real incomes change. It is a first-order approximation that ignores the re-optimization of housing consumption and of location in response to price changes. By the envelope theorem those responses are second order for small price changes, but the rent change here is not trivially small, so the calculation is best read as an upper bound on renters' losses and a lower bound on gains.

The analysis concerns incumbent residents, meaning households present in the market before treatment who remain afterward. Remote arrivals are excluded: they chose to move, so by revealed preference they are at least as well off as in their previous location, and their welfare is not the object of the local policy question. Households that leave a treated market because of rising rents are also outside the calculation as written, although Section 6.7.4 returns to them.

### 6.7.2 Renters

Using the utility function in Equation (6.1), the first-order change in indirect utility for an incumbent renter $i$ with housing share $s_i$ is

$$
\Delta \ln V^{R}_{i} \approx \Delta \ln w_{i} - s_{i} \, \Delta \ln r \tag{?}
$$

which is the proportional change in income net of the proportional change in the cost of the housing the household consumes. In the model of Chapter 3 the housing share is the common parameter $\alpha$, but in applying the formula to households it is useful to let it vary, since housing shares differ widely across households with different incomes. Equation (6.4) shows that a renter gains if and only if the wage gain exceeds the rent increase weighted by the housing share. Setting the expression to zero gives the threshold housing share above which a renter loses:

$$
s^{*} = \frac{\Delta \ln w}{\Delta \ln r} \tag{?}
$$

For a renter employed in non-tradable services, the relevant estimates are the wage effect of 1.2 percent and the rent effect of 4.6 percent from Table 6.1, so Equation (6.5) gives $s^{*} = 1.2 / 4.6 \approx 0.26$. A non-tradable renter household that spends more than about 26 percent of its income on rent is made worse off by the arrival of remote workers, despite working in the sector whose employment and wages the arrivals expand. This is the empirical counterpart of the renter case of Proposition 3.3.

The threshold is estimated with considerable uncertainty. A delta-method standard error that ignores the covariance between the wage and rent estimates is about 0.13. Because the two estimates are positively correlated across bootstrap replications, as one would expect when both respond to the same inflow, the bootstrap standard error of the ratio is somewhat smaller, at about 0.11. A 95 percent interval for $s^{*}$ therefore runs from roughly 0.04 to 0.48. The qualitative conclusion, that a renter with a moderate or high housing share is unlikely to gain, holds across most of this interval; the exact threshold is not precisely pinned down.

For renters employed outside non-tradable services the calculation is less favorable still. Tradable employment did not respond to treatment, and I have no evidence of a wage response in that sector; the model predicts none, since tradable wages are tied to national output prices. For a tradable-sector renter, Equation (6.4) then reduces to $-s_i \, \Delta \ln r$, a loss at any positive housing share. The same holds for renters outside the labor force, such as retirees living on fixed incomes, for whom the wage term is zero.

### 6.7.3 Owners

For an incumbent owner-occupier, the rent increase has two sides. As a consumer of housing, the owner faces a higher implicit rent; as the owner of the asset, the owner receives that higher implicit rent. To first order these cancel, so the owner's flow utility is affected only through the wage. The owner also receives a capital gain on the house, which can be converted into an equivalent flow by annuitizing it. Writing $P_{i0}$ for the initial value of the house, $Y_i$ for household income and $\rho$ for the annuitization rate, the change in the owner's real income is approximately

$$
\Delta \ln V^{O}_{i} \approx \Delta \ln w_{i} + \rho \, \frac{P_{i0}}{Y_{i}} \, \Delta \ln P \tag{?}
$$

where $\Delta \ln P$ is the house price response. The second term is the annuitized capital gain expressed as a share of income. It is realized in consumption only if the owner sells, borrows against the house or bequeaths it, but it is a real increase in wealth regardless.

Equation (6.6) makes clear that incumbent owners gain unambiguously from remote arrivals: both terms are non-negative given the estimates in Table 6.1. The size of the gain depends on the ratio of house value to income and on the annuitization rate, neither of which the panel measures. To illustrate, I use a value-to-income ratio of 3 and an annuitization rate of 0.04. These are round numbers chosen for exposition, not calibrated to any population. With a house price effect of 6.3 percent, the capital-gain term contributes about 0.76 percentage points of income, so an owner employed in non-tradable services gains roughly 2.0 percent in real income and a tradable-sector owner roughly 0.8 percent.

### 6.7.4 Stylized households

Table 6.3 brings these cases together. Each row is a stylized incumbent household defined by sector, tenure and, for renters, housing share; each entry applies Equations (6.4) and (6.6) to the main estimates.

**Table 6.3** Back-of-envelope incidence for stylized incumbent households

| Household | Wage change (%) | Housing share or value-to-income ratio | Housing-cost term (pp) | Capital-gain term (pp) | Net real-income change (%) |
|---|---|---|---|---|---|
| NT worker, renter | 1.2 | $s = 0.20$ | −0.92 | 0 | +0.3 |
| NT worker, renter | 1.2 | $s = 0.26$ | −1.20 | 0 | 0.0 |
| NT worker, renter | 1.2 | $s = 0.35$ | −1.61 | 0 | −0.4 |
| NT worker, renter | 1.2 | $s = 0.45$ | −2.07 | 0 | −0.9 |
| Tradable worker, renter | 0.0 | $s = 0.25$ | −1.15 | 0 | −1.2 |
| NT worker, owner-occupier | 1.2 | $P/Y = 3$ | 0 | +0.76 | +2.0 |
| Tradable worker, owner-occupier | 0.0 | $P/Y = 3$ | 0 | +0.76 | +0.8 |

*Note.* Calculations use the Callaway–Sant'Anna estimates in Table 6.1: non-tradable wage +1.2 percent, median asking rent +4.6 percent, house price index +6.3 percent. The tradable wage change is set to zero because tradable employment shows no response and tradable wages are not estimated. The capital-gain term uses an annuitization rate of $\rho = 0.04$. Housing shares and value-to-income ratios are illustrative and are not estimated from the panel.

The table shows the incidence in compact form. The arrival of remote workers produces a ranking of incumbents that runs from owners at the top, who gain on both margins, through non-tradable renters with low housing shares, who gain a little, down to non-tradable renters with high housing shares and to all renters outside the expanding sector, who lose. The spread between the best-off and worst-off rows of the table is about 3 percentage points of real income, large relative to the wage gain that the shock produces in the sector it expands.

The table also understates the losses of renters who move. A renter who is displaced by rising rents and leaves the market loses any location-specific value attached to living there, which in the model is the amenity term $A_c$ together with any idiosyncratic attachment to place. The spatial-equilibrium model implies that the marginal mover is indifferent, but inframarginal households with strong attachment to the market suffer losses that the first-order calculation does not capture. The panel does not track individual households and cannot measure displacement; I flag this as a gap that a household-level panel would be needed to fill [source needed].

### 6.7.5 Aggregate incidence

The stylized households show the distribution of gains and losses, but not whether incumbents gain on net. Summing over incumbent residents of market $c$, and writing $I_c$ for the set of incumbent households, $R_i$ for the rent paid by household $i$ and $\mathcal{H}_c$ for the stock of housing units owned by incumbents, the first-order change in aggregate incumbent real income is

$$
\Delta \mathcal{W}_c \approx \underbrace{\sum_{i \in I_c} Y_i \, \Delta \ln w_i}_{\text{wage gains}} + \underbrace{\Delta \ln r \left( \sum_{h \in \mathcal{H}_c} R_h - \sum_{i \in I_c} R_i \right)}_{\text{net rent transfer}} \tag{?}
$$

where $R_h$ is the rent earned on unit $h$, whether paid by a tenant or implicit for an owner-occupier. The first term collects wage gains across incumbent workers. The second is the net transfer through rents. Among incumbents, rent increases are a pure transfer from renters to owners and landlords: what one pays, another receives. The term in parentheses is therefore the rent on units owned by incumbents but occupied by people who are not incumbents, that is, by the arriving remote workers and by new residents who moved in to take service jobs, less any rent that incumbents pay to absentee owners. If all housing is owned by incumbents, this term is positive, because the newcomers pay rent to local owners. If a large share of housing is owned by absentee landlords, part of the rent paid by incumbents leaks out of the market and the term can be smaller or negative.

As long as the incumbent-owned share of the housing stock is not small, Equation (6.7) implies that aggregate incumbent welfare rises. The wage term is positive because non-tradable wages rise and other wages do not fall, and the rent transfer is positive because newcomers pay higher rents on housing that incumbents own. This is the sense in which net local welfare is positive on average. It is a statement about the sum of gains and losses among incumbents, not about every incumbent, and Table 6.3 shows that the sum conceals large transfers between groups.

### 6.7.6 Why the incidence is regressive

The gains from remote arrivals accrue disproportionately to households that are already advantaged. Homeownership tends to rise with income and wealth, so the capital-gain term in Equation (6.6) goes mainly to households higher in the local distribution. Housing shares among renters tend to fall with income, so the threshold in Equation (6.5) is more likely to be exceeded by lower-income renters. Both regularities are standard in housing economics, though I do not quantify them here [source needed]. And the workers in jobs least amenable to remote work are, on the evidence of {{mongey2021|n}}, less educated, lower paid, holding fewer liquid assets and more likely to rent. Those are precisely the workers who fill the non-tradable jobs that remote arrivals create, and among them the renters with high housing shares are the ones for whom Table 6.3 shows net losses.

There is an instructive symmetry with the evidence of {{althoff2022|n}} on the pandemic period itself. They show that when high-skill workers in dense cities went remote and their spending on local consumer services fell, low-skill service workers in those cities bore much of the economic impact. The present chapter studies the other side of the same reallocation: the markets that gained remote residents. In these markets, low-skill service workers gain jobs and some wage growth, but those gains are partly or wholly offset by the rise in housing costs that the same arrivals bring. In both the losing and the gaining markets, low-wage service workers who rent are exposed to the geography of remote work through local demand. That exposure is unfavorable when remote workers leave, and only conditionally favorable when they arrive.

The regressivity result does not depend on the precise illustrative parameters in Table 6.3. It follows from the ordering of the three estimated effects, with rents and house prices rising by more than wages, together with the fact that the households facing the rent increase without the capital gain are disproportionately lower-income. The model in Chapter 3 delivers this ordering whenever housing supply is less elastic than labor supply, and Section 6.6 shows that the ordering is most pronounced exactly where housing is scarcest. Applying Equation (6.5) to the subgroup estimates in Table 6.2 gives a threshold housing share of $1.6 / 6.8 \approx 0.24$ in inelastic markets and $0.9 / 2.9 \approx 0.31$ in elastic markets. Housing-supply conditions therefore determine not only how many jobs remote arrivals create but also how widely the gains from those jobs are shared.

## 6.8 Threats to Interpretation

### 6.8.1 Composition of the wage measure

The non-tradable wage outcome is a mean over employed workers. If the new service jobs created by remote arrivals differ systematically in pay from existing jobs, the measured wage change mixes a change in pay for given jobs with a change in the composition of jobs. The direction of the composition effect is not obvious. Remote workers with high incomes might demand services, such as home renovation by the construction trades, that pay more than the average non-tradable job, which would bias the measured wage effect upward relative to the effect on pay for a given job. Alternatively, rapid expansion of food service and retail would bias it downward. Composition across the four industry groups can in principle be checked by estimating effects on each group's employment share, and I regard that as the first robustness exercise to add; composition within groups cannot be checked with market-level data at all. For the incidence calculation, an upward compositional bias would make Table 6.3 too optimistic for incumbent renters, which would strengthen rather than weaken the regressivity conclusion.

### 6.8.2 Asking rents and sitting tenants

Median asking rents measure the price of housing for households searching in the market. Incumbent renters on existing leases face contract rents, which adjust more slowly through lease renewals and may be held below market by landlords who value tenant stability. In the short run, therefore, the 4.6 percent asking-rent effect overstates the rent increase actually experienced by sitting tenants, and the incidence calculation in Section 6.7 overstates their losses. Over a horizon of several years, as leases turn over and households move within the market, contract rents converge toward asking rents and the overstatement diminishes. Because the event study in Figure 6.1 shows asking rents still rising at $e = 8$, the gap between asking and contract rents is unlikely to reverse the sign of the renter calculation over the medium run, but the first-year magnitudes should be read as upper bounds.

### 6.8.3 Spillovers between markets

The design treats markets as independent units. If remote arrivals in a treated market raise rents in neighboring markets, either because some arrivals settle across the boundary or because displaced incumbents move to neighboring markets and bid up rents there, then nearby control markets are affected by treatment, violating the stable unit treatment value assumption. Spillovers of this kind would raise rents in control markets and bias the rent effect toward zero. The market definitions follow the commuting-zone logic of {{tolbert1996|n}}, which groups counties by the intensity of commuting flows between them, and the reassessment by {{fowler2016|n}} shows that the resulting boundaries are sensitive to methodological choices. Because commuting zones are drawn to contain most commuting flows, they limit spillovers through the labor market, but they were not designed to contain housing search, and fully remote workers are by definition not constrained by commuting. Chapter 7 discusses spillovers further; for this chapter the relevant point is that they would make the rent estimates conservative.

### 6.8.4 Selection of destination markets

Remote workers are not randomly distributed across markets; they choose where to move. The treatment definition is based on realized inflows, so markets become treated partly because they are attractive. If the features that make a market attractive to remote workers also affect its rent trend independently of those workers, the rent effects would be biased. The flat pre-trends in Figure 6.1 and the sensitivity bounds in Section 6.5.3 address this concern for trends that would have been visible before treatment. They do not address shocks that coincide with treatment, such as a market-specific change in housing regulation enacted in the same quarter. With 306 treated markets across four cohorts, such coincidences would need to be systematic to drive the results. A related concern is that the rapid shift to remote work early in the pandemic {{brynjolfsson2020}} and its persistence afterward {{barrero2021stick;barrero2023evolution}} coincided with changes in interest rates and housing finance that affected all markets. Quarter fixed effects and the not-yet-treated comparison absorb any such shock common to all markets, but not one whose effect varies with market characteristics correlated with treatment timing.

### 6.8.5 Productivity of remote workers

The incidence calculation treats remote workers' incomes as set elsewhere and unaffected by where they live. If moving changed remote workers' productivity and hence their pay, for example because home working affects performance and promotion, as in the experimental evidence of {{bloom2015|n}}, the income they bring would differ from the income they earned before moving. This would alter the size of the local income shock but not the logic of the incidence calculation, which takes the observed price responses as given. The concern bears more on the interpretation of the multiplier in Chapter 5 than on the price effects here.

## 6.9 Conclusion

This chapter has examined the price side of remote-worker arrivals, and the evidence supports four conclusions. First, the arrival of remote workers raises non-tradable wages by about 1.2 percent, median asking rents by about 4.6 percent and house prices by about 6.3 percent, with no detectable effect on tradable employment. The robust estimators agree with one another, while static two-way fixed effects understates every effect by a third or more. Second, the rent effect builds steadily over the eight quarters after treatment, without anticipation, and survives moderate violations of parallel trends. Third, the effects vary across markets as the spatial-equilibrium model predicts: where housing supply is inelastic, rents rise by more than twice as much and non-tradable employment by less, the joint pattern implied by Propositions 3.1 and 3.2. Fourth, the incidence of remote arrivals is unequal. Incumbent owners gain on both the wage and the asset margin; non-tradable renters gain only if their housing share is below about 26 percent; renters outside the expanding sector lose. Averaged over incumbents, the arrivals raise local welfare, mainly because newcomers pay rent to local owners, but the distribution of gains is regressive.

These conclusions sharpen the reading of the employment results in Chapter 5. Remote workers do act as a new export base, and they do create local service jobs. But the conversion of their income into local well-being passes through the housing market, and where housing cannot expand, much of the benefit is captured by property owners rather than by the workers who fill the new jobs. The magnitudes here come from a panel constructed for this study, and their external validity rests on how closely its markets resemble the places to which remote workers actually move. What carries over more readily is the structure of the argument, in which quantity and price effects are estimated together and combined through an explicit incidence calculation.

Chapter 7 draws these strands together. It sets out the dissertation's contributions, considers what the results imply for housing-supply and local fiscal policy in places receiving remote workers, and discusses the limitations of the design, including the measurement of remote status, the timing of migration and the possibility of spillovers between markets, that future work with richer data would need to address.
