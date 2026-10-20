# Chapter 3: A Spatial Equilibrium Model with Remote Workers

This chapter develops the theoretical framework that organizes the empirical work of the dissertation. Its purpose is narrow and deliberate. I do not attempt a quantitative spatial model of the whole economy, of the kind that has been used to simulate how a metropolitan area reorganizes when telecommuting becomes permanent {{delventhal2022}}. Instead I build the smallest model that answers the question posed in Chapter 1: when remote-capable workers relocate to a local labor market, bringing incomes earned elsewhere, what happens to local non-tradable employment, to non-tradable wages and to housing rents, and who gains? The model has to do three things. It has to say why remote workers should raise local service employment at all, and by how much per worker. It has to say how the gain in local demand is divided between quantities (more jobs) and prices (higher wages and rents). And it has to say how that division depends on a characteristic of the market that can be measured, so that the empirical chapters can test the mechanism and not only the sign of an average effect.

The central idea is that remote workers are a new kind of export base. In the traditional account of local labor markets, a city's tradable sector sells its output to the rest of the country, and the income it earns is spent partly on locally produced services, which in turn employ local workers {{moretti2010;moretti2012}}. A remote worker is an export industry of one: her output is sold to an employer located elsewhere, her wage is set in a national market, and her spending on haircuts, restaurant meals, home repairs and childcare lands in the market where she lives. The arrival of remote workers therefore resembles the arrival of a new tradable plant, with two differences that the model makes precise. The remote worker brings no local production with her, so she does not compete with incumbent tradable firms for local labor. But she competes with incumbents for housing from the day she arrives.

The chapter proceeds as follows. Section 3.1 explains why a structural framework is useful for a study whose estimates come from a difference-in-differences design. Section 3.2 sets out the environment, states the five maintained assumptions and collects the notation in Table 3.1. Section 3.3 characterizes the equilibrium. Section 3.4 derives the comparative statics for employment, wages and rents and states Propositions 3.1 and 3.2. Section 3.5 turns to incidence and states Proposition 3.3. Section 3.6 considers extensions. Section 3.7 provides a numerical illustration, and Section 3.8 maps the model's objects into the estimands of Chapters 4 to 6. Section 3.9 summarizes. Proofs of the propositions are in Appendix C.

## 3.1 Why a Model

A reader might reasonably ask why a dissertation built around a staggered difference-in-differences design needs a model at all. The design, described in Chapter 4, identifies average treatment effects on the treated under a parallel-trends assumption; it does not require a structural account of why the effects arise. There are three reasons to provide one nonetheless.

The first is interpretation. A difference-in-differences estimate of the effect of remote-worker arrivals on log non-tradable employment is a reduced-form number. Whether it is large or small depends on how big the inflow was and on how much of the new local spending would be expected to reach services rather than housing. The model supplies the mapping between the reduced-form effect and an economically interpretable object, the local job multiplier, which can be compared with the multipliers estimated for traditional tradable employment {{moretti2010}}. Without such a mapping, the estimate of Chapter 5 would be a percentage change without a benchmark.

The second reason is that the model generates predictions beyond the sign of the main effect, and those predictions discipline the empirical work. If the mechanism is local demand, tradable employment should not respond much. If housing absorbs part of the demand shock, the employment response should be smaller and the rent response larger where housing supply is inelastic. These are predictions about the joint pattern of several outcomes across several kinds of markets. A confounding story, in which remote workers simply choose markets that were about to boom for other reasons, would have to reproduce that whole pattern, which is harder than reproducing a single positive coefficient.

The third reason is incidence. The policy question that motivates much public discussion of remote work and places is distributional: do incumbent residents of the towns and smaller cities that received remote workers benefit from their arrival, or are they priced out? The answer depends on the relative size of wage and rent responses and on who owns housing, and a welfare statement requires a framework in which wages and rents enter utility in a known way. The spatial-equilibrium approach, in which mobile workers trade off wages, rents and amenities across places, supplies exactly that structure {{moretti2012;glaeser2001}}, and it is the approach I adopt.

It is worth being explicit about what the model is not. It is not an account of why some workers became able to work remotely; that question belongs to the literature on the feasibility of working from home {{dingel2020}} and on why the shift persisted after the pandemic {{barrero2021stick;barrero2023evolution}}, reviewed in Chapter 2. Nor is it an account of reorganization within metropolitan areas, such as the movement of residents and real-estate demand from city centers to suburbs documented by {{ramani2021|n}} and the flattening of the bid-rent curve documented by {{gupta2022|n}}. The unit in the model is the local labor market as a whole, the same unit used in the empirical chapters. The model takes as given that some remote workers arrive in some markets and asks what follows.

## 3.2 The Environment

### 3.2.1 Markets, goods and agents

The economy consists of a large number of local labor markets indexed by $c$. Each market is small relative to the national economy, so that conditions in any one market do not affect national wages, the national price of traded goods or the utility that workers can obtain elsewhere. This small-open-economy assumption is the theoretical counterpart of the empirical design, in which each of 600 markets is compared with others whose outcomes are assumed not to be affected by its treatment.

There are three goods. A traded good is produced in many markets, sold nationally and serves as the numeraire. A non-tradable service, which stands for the retail, food-service, personal-service and construction-trade activities measured in the data, is produced and consumed within each market. Housing is supplied locally at rent $r_c$.

Residents are of three types. Local tradable workers number $N^T_c$ and are employed by tradable firms located in the market at wage $w^T_c$. Non-tradable workers number $N^{NT}_c$ and are employed in local services at wage $w^{NT}_c$. Remote workers number $R_c$; they are employed by firms located elsewhere and earn a wage $w^R$ that is set nationally and does not depend on where they live. Each worker supplies one unit of labor inelastically. Workers of different types are not substitutes in production: a remote worker does not take a local service job, and a non-tradable worker cannot work remotely. The assumption is stark, but it matches the occupational facts reviewed in Chapter 2, in which the jobs that can be done at home and the in-person service jobs that depend on local demand are performed by largely different workers {{dingel2020;mongey2021}}.

Table 3.1 collects the notation. Throughout, a hat denotes a small proportional change, $\hat{x} \equiv d\ln x$.

**Table 3.1** Notation for the spatial equilibrium model

| Symbol | Definition |
|---|---|
| $c$ | Local labor market |
| $N^T_c,\ N^{NT}_c$ | Local tradable and non-tradable workers residing in $c$ |
| $R_c$ | Remote workers residing in $c$ |
| $w^T_c,\ w^{NT}_c$ | Wages of local tradable and non-tradable workers |
| $w^R$ | Remote wage, set nationally |
| $r_c$ | Housing rent per unit of housing |
| $A_c$ | Amenity value of market $c$ |
| $\bar{u}$ | National reservation utility of non-tradable workers |
| $\alpha$ | Housing expenditure share |
| $s$ | Expenditure share on local non-tradable services |
| $\eta_c$ | Elasticity of local housing supply |
| $\kappa_c \equiv 1/\eta_c$ | Inverse housing supply elasticity |
| $\bar{H}_c$ | Housing supply shifter (land, existing stock) |
| $B_c$ | Export base: tradable wage bill plus remote income |
| $Y_c$ | Total income of residents of market $c$ |
| $\theta_c \equiv w^R R_c / B_c$ | Remote share of the export base |
| $\sigma_i$ | Housing expenditure share of household $i$ |

*Note.* All parameters are positive, with $\alpha + s < 1$; the remainder of each budget is spent on the traded good.

### 3.2.2 Preferences and mobility

Residents have Cobb–Douglas preferences over housing and a composite of traded goods and local services, and they value the amenities of the market in which they live. Up to a constant, the indirect utility of a resident with income $w$ in market $c$ is

$$
U = \ln w - \alpha \ln r_c + A_c \tag{?}
$$

where $\alpha \in (0,1)$ is the housing expenditure share and $A_c$ the amenity value of the market. Equation (3.1) omits a term in the price of local services. This keeps the algebra transparent, and Section 3.6.1 shows that including it rescales $\alpha$ without changing any sign in the propositions.

Non-tradable workers can move freely between markets, so in equilibrium their utility in market $c$ equals the national reservation level $\bar{u}$. Applying Equation (3.1) to a non-tradable worker, the wage that a market must pay to retain its service workforce is

$$
\ln w^{NT}_c = \bar{u} - A_c + \alpha \ln r_c \tag{?}
$$

Equation (3.2) is a compensating-differential condition. A market with higher rents must pay its service workers more, by an elasticity equal to the housing share, and a market with better amenities can pay them less. Because $\bar{u}$ is fixed for a small market, the local supply of service workers is perfectly elastic at the wage given by Equation (3.2). Free mobility is a long-run idealization, and Section 3.6.2 shows how the conclusions change when the local labor supply curve slopes upward.

### 3.2.3 Production and local demand

Tradable firms produce the traded good with local labor and sell it on national markets. Over the horizon studied, which in the empirical chapters is roughly two years after treatment onset, I take tradable employment and the tradable wage as predetermined: tradable output is priced nationally and is not affected to first order by local demand. Section 3.6.3 relaxes this.

Non-tradable services are produced with labor alone under constant returns, with one unit of labor producing one unit of service, so that the service price equals $w^{NT}_c$. Every resident spends a share $s$ of income on local services. Because services cannot be traded, the service market clears when the sector's wage bill equals local spending on its output:

$$
w^{NT}_c N^{NT}_c = s\, Y_c \tag{?}
$$

where total resident income is the sum of the three wage bills,

$$
Y_c = w^T_c N^T_c + w^R R_c + w^{NT}_c N^{NT}_c \tag{?}
$$

Equation (3.3) applies the service share to all resident income, including the income of service workers themselves. This is the source of the multiplier: spending on services generates service income, part of which is spent on services in turn.

### 3.2.4 Housing

Housing is supplied by local owners with a constant elasticity,

$$
H^S_c = \bar{H}_c\, r_c^{\eta_c}, \qquad \eta_c \geq 0 \tag{?}
$$

where $\bar{H}_c$ captures land and the existing stock and $\eta_c$ summarizes the physical and regulatory constraints on building. It is the market characteristic on which the heterogeneity analysis of Chapter 6 conditions. Since residents spend a share $\alpha$ of income on housing, housing demand is $\alpha Y_c / r_c$.

### 3.2.5 The remote-work shock

The shock the model studies is an increase in $R_c$. Its source, in the period covered by the panel, is a national change in the feasibility and acceptability of working remotely, which released some workers from the requirement to live within commuting distance of their employer {{barrero2021stick;barrero2023evolution}}. Which markets received remote workers depended on how they ranked in remote workers' utility. Applying Equation (3.1) to a remote worker, whose wage is the same everywhere, a market's attractiveness is $\ln w^R - \alpha \ln r_c + A_c$, and with heterogeneous tastes for places the change in a market's remote stock takes the form

$$
\hat{R}_c = \varepsilon^R \left( dA_c - \alpha\, \hat{r}_c \right) + \hat{\zeta}_c
$$

where $\varepsilon^R$ is a migration elasticity and $\hat{\zeta}_c$ collects the national shift, such as the relaxation of employer location requirements, as it bears on market $c$. The expression makes two points. The realized inflow is itself an equilibrium object, because a market that attracts remote workers sees rents rise, which deters some further arrivals. And the selection of markets into treatment is not random: markets with high amenities and low rents are the natural destinations. If amenity-rich markets were on different trajectories for other reasons, a naive comparison would be biased, a concern Chapter 4 takes up.

For the comparative statics I treat the realized change in $R_c$ as the shock and derive the responses of the other endogenous variables conditional on it. This is the natural choice because the empirical treatment in Chapter 4 is defined by the realized net in-migration of remote-capable workers, not by an underlying shifter, and because conditioning on the inflow separates the local economy's response, which is the subject of the dissertation, from the question of where remote workers choose to go. I assume that every market has some remote workers before the shock, so that $\hat{R}_c$ is well defined.

### 3.2.6 Maintained assumptions

The results below rest on five assumptions, which I state together for reference. They are numbered as in Appendix C, where the proofs use them.

- **(A1) Mobility of non-tradable workers.** Non-tradable workers are freely mobile across markets, so that their utility in market $c$ equals $\bar{u}$ and Equation (3.2) holds.
- **(A2) Non-tradable demand.** Residents spend a share $s \in (0,1)$ of income on local non-tradable services, with $\alpha + s < 1$; services are produced one-for-one with labor, so the service price equals $w^{NT}_c$.
- **(A3) Predetermined tradable sector.** Over the horizon studied, tradable employment $N^T_c$ and the tradable wage $w^T_c$ are fixed.
- **(A4) Housing supply.** Housing is supplied by local owners according to $H^S_c = \bar{H}_c r_c^{\eta_c}$, and housing demand is $\alpha Y_c / r_c$.
- **(A5) Remote arrivals as the shock.** The remote wage $w^R$ is set nationally, and changes in $R_c$ are treated as given in the comparative statics.

Utility takes the form of Equation (3.1) throughout. Of the five assumptions, (A1) and (A3) are the ones most likely to be violated over the short horizons of the empirical work, and Section 3.6 examines what happens when each is relaxed.

## 3.3 Equilibrium

### 3.3.1 The export base and the income multiplier

Given $\bar{u}$, $w^R$, the predetermined tradable sector and the remote stock $R_c$, an equilibrium in market $c$ is a non-tradable wage $w^{NT}_c$, a rent $r_c$, non-tradable employment $N^{NT}_c$ and a housing stock such that Equations (3.2) to (3.5) hold and the housing market clears.

The structure of the service market gives a clean characterization of local income. Define the export base as the income that residents earn outside the local service sector,

$$
B_c \equiv w^T_c N^T_c + w^R R_c
$$

Substituting Equation (3.3) into Equation (3.4) gives $Y_c = B_c + s Y_c$, and therefore

$$
Y_c = \frac{B_c}{1-s}, \qquad N^{NT}_c = \frac{s}{1-s}\, \frac{B_c}{w^{NT}_c} \tag{?}
$$

Equation (3.6) is a local version of the export-base multiplier. Each unit of income earned from outside the market supports $s/(1-s)$ units of service income, and total resident income is a multiple $1/(1-s)$ of the base. The two components of the base enter symmetrically: a dollar of remote income has exactly the same effect on the service wage bill as a dollar of tradable wages. This is the formal sense in which a remote worker is an export industry of one, and it is why the multipliers estimated for tradable employment {{moretti2010}} are the right benchmark for the estimates of Chapter 5.

Let $\theta_c \equiv w^R R_c / B_c$ be the remote share of the export base. Under (A3) only the remote component of the base changes, so

$$
\frac{d\ln B_c}{d\ln R_c} = \frac{d\ln Y_c}{d\ln R_c} = \theta_c \tag{?}
$$

I refer to $\theta_c \hat{R}_c$ as the local income shock. It equals the additional remote income relative to the initial export base, $w^R dR_c / B_c$, and it is the single summary of the inflow that the rest of the model needs. A given proportional increase in the remote stock matters more where remote workers already form a large part of the base. By Equation (3.6), the service wage bill rises in the same proportion as income, so that $\hat{w}^{NT}_c + \hat{N}^{NT}_c = \theta_c \hat{R}_c$.

### 3.3.2 Rents

Housing market clearing under (A4) requires $\alpha Y_c / r_c = \bar{H}_c r_c^{\eta_c}$, so that

$$
\ln r_c = \frac{1}{1+\eta_c} \left[ \ln\alpha + \ln Y_c - \ln \bar{H}_c \right] \tag{?}
$$

Housing expenditure is a fixed share of income, so any increase in income must be absorbed by some combination of more housing and a higher rent. The division depends on $\eta_c$. When supply is perfectly inelastic, $\eta_c = 0$, the whole increase in housing expenditure takes the form of higher rents; as supply becomes perfectly elastic, rents do not move. It will be convenient to work with the inverse elasticity $\kappa_c \equiv 1/\eta_c$, which runs from zero for perfectly elastic supply to infinity for perfectly inelastic supply, and to note that $1/(1+\eta_c) = \kappa_c/(1+\kappa_c)$ is increasing in $\kappa_c$.

### 3.3.3 The three responses

Differentiating Equation (3.8) and using Equation (3.7), then Equation (3.2), then the second part of Equation (3.6), gives the three responses on which the propositions rest:

$$
\frac{d\ln r_c}{d\ln R_c} = \frac{\theta_c}{1+\eta_c} \tag{?}
$$

$$
\frac{d\ln w^{NT}_c}{d\ln R_c} = \frac{\alpha\,\theta_c}{1+\eta_c} \tag{?}
$$

$$
\frac{d\ln N^{NT}_c}{d\ln R_c} = \theta_c \left( 1 - \frac{\alpha}{1+\eta_c} \right) \tag{?}
$$

The logic runs in a chain. Remote income raises resident income by $\theta_c \hat{R}_c$ (Equation (3.7)). Higher income raises housing demand, and rents rise by a fraction $1/(1+\eta_c)$ of the income shock (Equation (3.9)). Service workers must be compensated for higher rents, so the service wage rises by $\alpha$ times the rent increase (Equation (3.10)). The service wage bill rises by the full income shock, and whatever part of it is not absorbed by the higher wage takes the form of more service jobs (Equation (3.11)).

The structure makes clear where housing supply enters. It does not change the size of the demand shock to services, which is fixed by the export-base multiplier. It changes how that shock is divided between the service wage and service employment, because the wage must keep pace with rents to retain mobile workers. In a market where housing is elastically supplied, almost all of the increase in the service wage bill takes the form of new jobs. Where it is inelastic, a larger part takes the form of a higher wage.

## 3.4 Comparative Statics: Employment, Wages and Rents

### 3.4.1 Employment and the local multiplier

The elasticity in Equation (3.11) describes proportional effects. The object most often reported in the local-multiplier literature is the number of additional local jobs per additional worker in the export sector {{moretti2010}}. Since $dN^{NT}_c/dR_c = (N^{NT}_c/R_c)\, d\ln N^{NT}_c/d\ln R_c$, using Equation (3.6) and $\theta_c/R_c = w^R/B_c$, the job multiplier is

$$
\frac{dN^{NT}_c}{dR_c} = \frac{s}{1-s} \cdot \frac{w^R}{w^{NT}_c} \cdot \left( 1 - \frac{\alpha}{1+\eta_c} \right) \tag{?}
$$

Equation (3.12) decomposes the multiplier into three intuitive pieces. The first, $s/(1-s)$, is the spending multiplier: the service income supported by a unit of export-base income. The second, $w^R/w^{NT}_c$, converts income into jobs. A remote worker who earns more than a service worker brings enough spending to support more than one service wage bill's worth of demand, and this ratio is why a remote-worker multiplier could in principle be sizable. The third, $1 - \alpha/(1+\eta_c)$, is the share of the increase in the service wage bill that takes the form of jobs rather than higher wages. It is here that housing supply enters.

**Proposition 3.1** (Local service multiplier). *Under (A1)–(A5), the elasticity of non-tradable employment with respect to the remote-worker stock, $d\ln N^{NT}_c / d\ln R_c$, is strictly positive. It is strictly decreasing in the inverse housing supply elasticity $1/\eta_c$, and it lies between $\theta_c(1-\alpha)$, attained when housing supply is perfectly inelastic, and $\theta_c$, attained when it is perfectly elastic. The implied local multiplier is given by Equation (3.12).*

*Proof.* See Appendix C.

The content of Proposition 3.1 beyond the sign is the dependence on housing supply. A market with less elastic housing supply converts a given income shock into fewer service jobs, because the same increase in local spending raises rents by more, and higher rents require a higher service wage to retain mobile workers. A larger share of the increased wage bill is therefore paid out as a higher wage rather than as more jobs. The bounds are also informative. Even in the most inelastic market, employment rises by a fraction $1-\alpha$ of the income shock. Housing constraints dampen the multiplier but cannot eliminate it, because the compensating wage rise is only a fraction $\alpha$ of the rent rise, and the rent rise cannot exceed the income shock.

A tradable job in the same framework would yield a multiplier of the same form, with the tradable wage in place of the remote wage. The remote-worker multiplier differs in two respects that the formula does not show. A remote worker adds no local output, so she brings none of the local purchases of intermediate inputs that a tradable plant makes. And she may differ from an incumbent in her spending pattern, for example by spending more on housing because her home is also her workplace. Both differences tend to make the remote-worker multiplier smaller than the tradable one, and Section 3.6.4 returns to the second.

### 3.4.2 Rents, wages and house prices

The price side of the comparative statics follows from Equations (3.9) and (3.10). In terms of the inverse elasticity, the rent response is $\theta_c \kappa_c/(1+\kappa_c)$, which is positive whenever supply is less than perfectly elastic and increasing in $\kappa_c$. The wage response is exactly $\alpha$ times the rent response, so it inherits both properties. Rents and wages rise together, and both rise more where housing supply is less elastic.

**Proposition 3.2** (Rents and wages). *Under (A1)–(A5), the elasticities of the rent and of the non-tradable wage with respect to the remote-worker stock are strictly positive whenever housing supply is less than perfectly elastic, and both are strictly increasing in $1/\eta_c$. The ratio of the wage response to the rent response equals $\alpha$ and does not depend on $\eta_c$.*

*Proof.* See Appendix C.

The second part of Proposition 3.2 is a strong restriction, and it is a consequence of free mobility. If service workers must be held to their reservation utility, the wage cannot rise by more than is needed to compensate them for higher rents, and the compensation needed is fixed by the housing share. The restriction links the wage and rent estimates to the incidence calculation of Section 3.5, and Section 3.6.2 shows how it is loosened when mobility is imperfect.

House prices follow from rents through capitalization. Let $P_c$ be the price of a unit of housing, $i$ the discount rate and $g_c$ the expected growth rate of rents. In a stationary asset-market equilibrium $P_c = r_c/(i - g_c)$, so that

$$
\hat{P}_c = \hat{r}_c + \frac{dg_c}{i - g_c} \tag{?}
$$

If the arrival of remote workers is expected to be a one-off shift, house prices rise in the same proportion as rents. If buyers expect further arrivals, or expect rents to continue rising while supply responds slowly, the second term is positive and house prices rise by more than current rents. Since the empirical treatment is an absorbing state in which inflows continue for several quarters after onset, the second case is the more plausible description of the panel. The static model itself does not generate the second term, so a price response that exceeds the rent response should be read as evidence about expectations rather than as a test of the propositions.

Propositions 3.1 and 3.2 together deliver the prediction that is the sharpest test of the mechanism. Across markets that receive similar inflows, a less elastic housing supply should produce a smaller employment response and a larger rent response. The prediction concerns opposite movements in two outcomes in response to a single market characteristic, and it is not easily mimicked by alternative explanations. If remote workers simply chose markets that were about to grow for unrelated reasons, there is no particular reason why that growth should take the form of jobs in elastic markets and of rents in inelastic ones.

### 3.4.3 The division of the income shock

It is helpful to see the comparative statics in a single picture. Per unit of income shock $\theta_c \hat{R}_c$, the service wage bill rises by one unit, divided between employment, $1 - \alpha/(1+\eta_c)$, and the wage, $\alpha/(1+\eta_c)$, while rents rise by $1/(1+\eta_c)$.

> **Figure 3.1** Division of the local income shock as housing supply becomes less elastic. The horizontal axis is $1/(1+\eta_c)$, from 0 (perfectly elastic supply) to 1 (perfectly inelastic supply). Three lines are drawn per unit of income shock: the employment response, falling linearly from 1 to $1-\alpha$; the wage response, rising linearly from 0 to $\alpha$; and the rent response, rising linearly from 0 to 1. The employment and wage lines always sum to one, and the wage line is always $\alpha$ times the rent line.

Figure 3.1 makes visible an implication that will matter when the model meets the data. In the baseline the rent response cannot exceed the response of the service wage bill:

$$
\frac{\hat{r}_c}{\hat{w}^{NT}_c + \hat{N}^{NT}_c} = \frac{1}{1+\eta_c} \leq 1 \tag{?}
$$

The left-hand side of Equation (3.14) involves only observable responses. A ratio above one is incompatible with the baseline, and would indicate that something in addition to the income of remote workers is raising housing demand, most naturally a higher housing intensity of remote workers themselves. Section 3.8 returns to this.

## 3.5 Incidence

### 3.5.1 Who gains and who loses

The comparative statics describe prices and quantities. Welfare depends on which households face those prices and on what they own. Consider an incumbent household $i$ that lives in market $c$ before the shock and remains there after it, with income $y_i$ from wage $w_i$, and let $\sigma_i$ be the share of its income spent on housing. Holding the household's housing consumption at its initial level, the first-order change in money-metric welfare, as a share of income, is the change in its log wage less its housing share times the change in log rent:

$$
\frac{dV_i}{y_i} = d\ln w_i - \sigma_i\, d\ln r_c = \left( \frac{d\ln w_i}{d\ln r_c} - \sigma_i \right) d\ln r_c \tag{?}
$$

Because rents rise, a renter household loses on net if and only if its housing share exceeds the ratio of the response of its wage to the response of the rent. For a household earning the non-tradable wage, Proposition 3.2 fixes that ratio at $\alpha$. A renter in non-tradables whose housing share equals $\alpha$ is exactly compensated, which is the indifference condition implied by free mobility. A renter with a higher housing share loses, and one with a lower share gains. For a household in the tradable sector, whose wage does not respond under (A3), the ratio is zero, and any renter in that sector loses.

Owners are in a different position. A household that owns its home is both landlord and tenant: the higher implicit rent it pays is received by itself. More generally, the owners of the initial housing stock receive the rent increase that renters pay on that stock. Because new units are supplied at a marginal cost equal to the rent, they add nothing at first order, and the first-order gain to owners equals the first-order increase in renters' payments on the initial stock.

**Proposition 3.3** (Incidence). *Consider a small increase in $R_c$ under (A1)–(A5), holding incumbents' housing consumption at its initial level. To first order: (i) mobile non-tradable workers, including new entrants to the sector, are no better or worse off, their utility remaining $\bar{u}$; (ii) an incumbent renter household with housing share $\sigma_i$ loses on net if and only if $\sigma_i$ exceeds $d\ln w_i / d\ln r_c$, which equals $\alpha$ for a household earning the non-tradable wage and zero for a household in the tradable sector; (iii) incumbent owners of the initial housing stock capture the rent increase that renters pay on that stock, so that this transfer nets to zero, and the sum of first-order gains across incumbent residents and owners is strictly positive.*

*Proof.* See Appendix C.

The positive aggregate gain in part (iii) is the wage gain of incumbent non-tradable workers, which is not offset by any loss once the rent transfer between renters and owners is netted out. The proposition therefore says that remote arrivals are good for a market's incumbents in aggregate, but that the aggregate gain is the sum of a modest wage gain and a transfer from renters to owners.

### 3.5.2 From the model's threshold to the data

The threshold in part (ii) is the bridge between the model and the incidence calculation of Chapter 6. In the model the ratio of the wage response to the rent response is a preference parameter, $\alpha$. In the data it is an estimable ratio of two treatment effects. If the wage and rent estimates of Chapter 6 are taken at face value, a renter in non-tradables loses whenever its housing share exceeds the ratio of the estimated wage effect to the estimated rent effect, $1.2/4.6 \approx 0.26$. The model's prediction that this ratio equals the average housing share is itself testable: a ratio well below plausible housing shares would indicate that wages respond less than full compensation requires, as they would if service workers are not freely mobile in the short run.

The distributional message of Proposition 3.3 follows from who rents. Whether the gains from remote arrivals are regressive depends on how housing shares and homeownership vary with income. The workers employed in the in-person, low-wage service jobs that benefit from local demand are, as a group, less educated, lower paid, less likely to hold liquid assets and more likely to rent than workers who can work from home {{mongey2021}}. The households that the model identifies as most likely to lose are therefore disproportionately the ones employed in the sector that the multiplier expands. That tension between the jobs a remote inflow creates and the rents it raises is the subject of Chapter 6.

The incidence analysis has three limitations. It is first order, so it is accurate only for small shocks. It concerns incumbents who stay, and it is silent about renters who are displaced to other markets, whose loss is bounded by the value of their next-best option. And it treats each household's sector as fixed, so it does not capture gains to incumbents who move from tradable work, or from non-employment, into the expanding service sector.

## 3.6 Extensions

### 3.6.1 The price of local services

Equation (3.1) omits the price of local services. If indirect utility includes it, $U = \ln w - \alpha \ln r_c - s \ln w^{NT}_c + A_c$, then (A1) implies $(1-s)\ln w^{NT}_c = \bar{u} - A_c + \alpha \ln r_c$, and every step above goes through with $\alpha$ replaced by

$$
\tilde{\alpha} \equiv \frac{\alpha}{1-s} \tag{?}
$$

Because $\alpha + s < 1$, $\tilde{\alpha} < 1$, so the employment elasticity $\theta_c[1-\tilde{\alpha}/(1+\eta_c)]$ remains strictly positive. All the monotonicity results, and the form of the threshold in Proposition 3.3, are unchanged, with the threshold now equal to $\tilde{\alpha}$. The extension makes the wage response larger, because service workers must now be compensated for the higher price of the services they consume as well as for higher rents.

### 3.6.2 Imperfect mobility

Free mobility, (A1), is the assumption most likely to fail over two years. Suppose instead that service workers differ in their attachment to market $c$, so that the local supply of service workers slopes upward with elasticity $\varepsilon^{NT}$ in the utility the market offers. The wage condition becomes

$$
\hat{w}^{NT}_c = \alpha\, \hat{r}_c + \frac{1}{\varepsilon^{NT}}\, \hat{N}^{NT}_c \tag{?}
$$

which adds a labor-supply component to the compensating-differential component of Equation (3.10). The free-mobility case is the limit $\varepsilon^{NT} \to \infty$. With finite $\varepsilon^{NT}$, the wage responds by more and employment by less for a given income shock, the marginal entrant to the sector is still indifferent, and inframarginal incumbent service workers gain $\hat{N}^{NT}_c/\varepsilon^{NT}$. The ratio of the wage response to the rent response then exceeds $\alpha$, and the threshold housing share in Proposition 3.3 rises accordingly. The signs and monotonicity in Propositions 3.1 and 3.2 survive, because the rent response is unchanged in form and the wage still rises with rents.

### 3.6.3 Crowding out and agglomeration in the tradable sector

Under (A3) tradable employment is fixed. If instead tradable workers are mobile and the tradable wage is pinned by national prices, higher rents drive some of them out. To first order,

$$
\hat{N}^T_c = -\varepsilon^T \alpha\, \hat{r}_c \leq 0 \tag{?}
$$

where $\varepsilon^T$ is the migration elasticity of tradable workers. Crowding out lowers the export base by an amount proportional to the rent increase, and therefore by more where housing is inelastic, which strengthens the monotonicity in Proposition 3.1. The sign of the employment effect remains positive provided the induced tradable outflow is small relative to the remote inflow.

Agglomeration economies could push the other way. A large literature argues that productivity rises with the scale of local activity through sharing, matching and learning {{duranton2004}}. Such economies operate across industrial, geographic and temporal scope and attenuate with distance {{rosenthal2004}}, and the productivity advantage of large cities appears to reflect agglomeration more than the selection of more productive firms {{combes2012}}. If remote workers raised the productivity of local tradable firms, tradable employment could rise. But the learning channel through which cities raise wages over time appears to operate through repeated interaction at work {{glaeser2001}}, and remote workers interact mainly with colleagues employed elsewhere. I therefore expect the tradable response to be small over the horizon studied. In the empirical chapters, tradable employment serves as a near-placebo whose predicted sign is zero or slightly negative.

Sharing and matching economies may apply with more force to local services, where variety and specialization rise with market size. If service productivity rises with the scale of the sector, the service price rises by less than the wage, the real wage of all residents rises with service employment, and the multiplier is amplified. This channel reinforces Proposition 3.1 without changing its comparative statics.

### 3.6.4 Housing-intensive remote workers

The baseline gives remote workers the same housing share as everyone else. A remote worker who works from home uses part of her dwelling as an office and may value space more because she spends more time in it. Suppose remote workers spend a share $\alpha_R > \alpha$ on housing, while locally employed workers spend $\alpha$. Housing expenditure becomes $\alpha(Y_c - w^R R_c) + \alpha_R w^R R_c$, and the rent response becomes

$$
\frac{d\ln r_c}{d\ln R_c} = \frac{\theta_c\, \chi_c}{1+\eta_c}, \qquad \chi_c \equiv \frac{\alpha + (\alpha_R - \alpha)(1-s)}{\alpha + (\alpha_R - \alpha)(1-s)\,\theta_c} \geq 1 \tag{?}
$$

The factor $\chi_c$ equals one when $\alpha_R = \alpha$ and exceeds one otherwise, because $\theta_c < 1$. Housing-intensive remote workers raise rents by more than their income alone implies, raise the compensating wage and lower the employment response. The ratio in Equation (3.14) becomes $\chi_c/(1+\eta_c)$, which can exceed one. The extension is consistent with the evidence that real-estate demand followed workers when working from home became common {{gupta2022;ramani2021}}, and it is the most natural way to reconcile the baseline with a rent response larger than the response of the service wage bill. The employment elasticity stays positive provided $\alpha\chi_c/(1+\eta_c) < 1$, which holds unless remote workers are much more housing-intensive than incumbents and supply is close to fixed.

### 3.6.5 Gradual adjustment and amenities

The model is static, but neither inflows nor the local response to them arrive at once. Two sources of gradualism are relevant for the event-study estimates of Chapter 5. The inflow itself builds after treatment onset: in the panel, the cumulative remote-worker inflow among treated markets reaches 1.9 percent of baseline employment by the eighth quarter after treatment. And service employment may adjust with a lag, as establishments open and expand. A partial-adjustment rule captures the second source:

$$
\hat{N}^{NT}_{c,e} = \hat{N}^{NT}_{c,e-1} + \nu \left( \hat{N}^{NT*}_{c,e} - \hat{N}^{NT}_{c,e-1} \right), \qquad 0 < \nu \leq 1 \tag{?}
$$

where $e$ indexes quarters since onset and $\hat{N}^{NT*}_{c,e}$ is the static response of Equation (3.11) evaluated at the cumulative inflow at $e$. If $\nu = 1$, employment tracks the inflow quarter by quarter; if $\nu < 1$, it lags, and the ratio of the employment response to the inflow rises with event time even after the inflow stops growing. Housing has its own dynamics. In the short run the stock is nearly fixed, so the effective $\eta_c$ is close to zero and rises as construction responds. Rent responses should therefore be front-loaded relative to their long-run values and employment responses back-loaded.

Amenities, finally, affect the size of the inflow but not the response per unit of inflow, since $A_c$ does not appear in Equations (3.9) to (3.11). Amenity-rich markets should show larger reduced-form effects because they receive more remote workers, not because they convert remote income into jobs more efficiently. A larger effect in amenity-rich markets is consistent with the model if it is accompanied by a larger inflow, and harder to reconcile with it otherwise. A richer version would let amenities themselves respond, since new restaurants and cafes are both a consequence of remote arrivals and an attraction for further arrivals; this feedback would reinforce the multiplier [source needed].

## 3.7 A Numerical Illustration

The model has few parameters, and it is instructive to see how they combine. Table 3.2 reports the employment, wage and rent responses per unit of income shock, and the implied job multiplier, for four housing-supply elasticities. The parameter values are illustrative, not estimated. I set the service share to $s = 0.3$, the housing share to $\alpha = 0.3$ and the ratio of the remote wage to the service wage to $w^R/w^{NT} = 1.8$.

**Table 3.2** Illustrative responses per unit of local income shock

| Housing supply elasticity $\eta_c$ | $1/(1+\eta_c)$ | Employment | Wage | Rent | Job multiplier |
|---|---|---|---|---|---|
| 2.33 (elastic) | 0.30 | 0.91 | 0.09 | 0.30 | 0.70 |
| 1.00 | 0.50 | 0.85 | 0.15 | 0.50 | 0.66 |
| 0.43 (inelastic) | 0.70 | 0.79 | 0.21 | 0.70 | 0.61 |
| 0 (fixed supply) | 1.00 | 0.70 | 0.30 | 1.00 | 0.54 |

*Note.* Computed from Equations (3.9) to (3.12) with $s = 0.3$, $\alpha = 0.3$ and $w^R/w^{NT} = 1.8$. Employment, wage and rent are elasticities per unit of $\theta_c \hat{R}_c$. Values are illustrative and are not estimates.

Three features of Table 3.2 stand out. First, the job multiplier is below one for every parameterization, even though a remote worker earns more than a service worker. Only a fraction $s$ of income is spent on local services, and part of the increased service wage bill takes the form of higher wages. A multiplier between one half and three quarters is the model's natural order of magnitude, below the multiplier of more than one estimated for tradable manufacturing jobs {{moretti2010}}; the comparison should be read with the omitted local input purchases of tradable firms in mind.

Second, moving from elastic to perfectly inelastic supply lowers the employment response by under a quarter but more than triples the rent response. The proportional sensitivity of rents to housing supply is much greater than that of employment, because supply enters the employment response only through the compensating wage, scaled by $\alpha$, whereas it enters the rent response directly.

Third, the wage response is small in every row, at most $\alpha$ times the income shock. Free mobility keeps the service wage on a tight leash: it rises only to compensate for higher rents.

To translate per-unit responses into the magnitudes of the empirical chapters, note that the income shock can be written $\theta_c \hat{R}_c = (w^R/\bar{y}_c)(dR_c/E_c)/(1-s)$, where $\bar{y}_c$ is average earnings and $E_c$ total employment. With a remote wage half as large again as average earnings and an inflow of 1.9 percent of baseline employment, the income shock is about 4 percent. The second row of Table 3.2 then implies a non-tradable employment response of about 3.5 percent, a wage response of about 0.6 percent and a rent response of about 2 percent. These magnitudes show only that the model's predictions are of the same order as the effects one might look for in data; they are not a test.

## 3.8 From the Model to the Estimands

The empirical chapters estimate average treatment effects on the treated, defined in Chapter 4 by Equations (4.3) and (4.4). This section states how those estimands relate to the objects of the model.

### 3.8.1 Treatment effects as reduced forms

Let $\hat{R}_c(e)$ denote the cumulative proportional change in the remote stock in treated market $c$ at $e$ quarters after onset, relative to its counterfactual path. With complete adjustment, the model implies that the effect of treatment on log non-tradable employment at event time $e$ is

$$
\tau^{NT}(e) = \mathbb{E}\left[ \theta_c \left( 1 - \frac{\alpha}{1+\eta_c} \right) \hat{R}_c(e) \,\middle|\, \text{treated} \right] \tag{?}
$$

an average, over treated markets, of the product of the market-specific elasticity in Equation (3.11) and the market-specific inflow. Two points follow. The treatment effect is not a structural parameter: it mixes the response per unit of inflow with the size of the inflow, both of which vary across markets and cohorts, so that differences in effects across cohorts can reflect differences in inflows rather than in responses. And because $\hat{R}_c(e)$ grows with $e$, the effect should grow with event time even if the response per unit is constant. Growth of this kind is precisely the dynamic heterogeneity that biases static two-way fixed-effects estimators in staggered designs {{goodmanbacon2021;sun2021}}, and it is one reason the empirical strategy relies on estimators robust to it.

### 3.8.2 The multiplier as a ratio of treatment effects

The structural object that the reduced forms recover most directly is the job multiplier. Converting $\tau^{NT}(e)$ into a number of jobs, by multiplying by baseline non-tradable employment, and dividing by the treatment effect on the number of remote workers at the same horizon gives

$$
\bar{m}(e) = \frac{\mathbb{E}\left[ N^{NT}_{c,0}\, \tau^{NT}_c(e) \,\middle|\, \text{treated} \right]}{\mathbb{E}\left[ \Delta R_c(e) \,\middle|\, \text{treated} \right]} \tag{?}
$$

an inflow-weighted average of the market-specific multipliers in Equation (3.12). Equation (3.22) has the form of a Wald estimator, with treatment status as the instrument and the remote inflow as the endogenous regressor. Its interpretation as a multiplier requires an exclusion restriction: treatment must affect non-tradable employment only through the inflow of remote workers and their income. Chapter 5 estimates the ratio and discusses the restriction. Under partial adjustment with $\nu < 1$, the ratio at short horizons understates the long-run multiplier.

### 3.8.3 Testable predictions

Collecting the results, the model makes the following predictions about the outcomes measured in the panel. They are stated here, before the evidence, so that the empirical chapters can be read against them.

1. Non-tradable employment rises after treatment, and the effect grows with event time as the inflow accumulates (Proposition 3.1 and Equation (3.21)).
2. The local job multiplier is positive and, for plausible parameter values, below one (Equation (3.12) and Table 3.2).
3. Non-tradable wages and rents rise, with the wage response a fraction of the rent response (Proposition 3.2), and house prices rise at least as much as rents if further arrivals are expected (Equation (3.13)).
4. Tradable employment responds little, with a predicted sign of zero in the baseline and negative under crowding out (Equation (3.18)).
5. Where housing supply is less elastic, the employment response is smaller and the rent response larger (Propositions 3.1 and 3.2).
6. Amenity-rich markets show larger reduced-form effects to the extent that they receive larger inflows (Section 3.6.5).
7. Incumbent renters with housing shares above the ratio of the wage response to the rent response lose, incumbent owners gain, and the distribution of gains is regressive where lower-paid workers are more likely to rent (Proposition 3.3).

Two ratios of estimates speak directly to the model's structure. By Proposition 3.2, the ratio of the wage effect to the rent effect estimates $\alpha$ under free mobility; with the overall effects reported in Chapter 6, a wage effect of 1.2 percent against a rent effect of 4.6 percent, it is about 0.26, which is a plausible housing share. By Equation (3.14), the ratio of the rent effect to the effect on the service wage bill cannot exceed one in the baseline. With an employment effect of 2.1 percent (Chapter 5) and the same wage and rent effects, it is about 1.4. The baseline therefore understates the rent response, and the estimates point toward the housing-intensity extension of Section 3.6.4, a short-run housing supply close to fixed, or both. The comparison is approximate, because the wage outcome is a mean over employed workers and the rent outcome is an asking rent that responds faster than rents on existing leases, but it shows how the model's parameters can be read off the reduced-form estimates.

## 3.9 Summary

This chapter has developed a spatial-equilibrium model in which remote workers act as a new export base for the markets they move to. Their income is earned in tradable work done for employers elsewhere, it is spent partly on local services, and through the export-base multiplier of Equation (3.6) it raises the local service wage bill. How that increase is divided between jobs and wages, and how much of the new demand is capitalized into rents, depends on the elasticity of local housing supply. Proposition 3.1 shows that non-tradable employment rises with the remote inflow and that the response is smaller where housing supply is less elastic. Proposition 3.2 shows that rents and non-tradable wages rise, by more where supply is less elastic, with the wage response a fraction $\alpha$ of the rent response. Proposition 3.3 shows that mobile service workers are held to their reservation utility, that renters with housing shares above the ratio of the wage response to the rent response lose, that owners capture the rent increase renters pay, and that the aggregate gain to incumbents is positive. Extensions to service prices, imperfect mobility, crowding out, housing-intensive remote workers and gradual adjustment leave these conclusions intact while sharpening the predictions for dynamics and for the tradable near-placebo.

The model makes predictions about average effects, about their evolution over time and about their variation with housing supply and amenities. Testing them requires a panel of local labor markets, a definition of treatment that captures the arrival of remote workers, and an estimator that remains valid when treatment effects differ across cohorts and grow over time. Chapter 4 provides all three.
