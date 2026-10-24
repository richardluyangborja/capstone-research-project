"""Pydantic contracts for the AI report endpoint.

The request is Laravel's aggregated, PII-free context. The response is the fixed prose shape the
Laravel mapper validates again: exactly three insights, exactly four actions, and IDs from the
variant's allow-list (the list is enforced in `reports.py`).
"""

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

Variant = Literal["executive", "pipeline", "client_health"]
Range = Literal["30d", "quarter", "ytd"]


class Period(BaseModel):
    model_config = ConfigDict(extra="forbid")

    range: Range
    start: str
    end: str


class Scope(BaseModel):
    model_config = ConfigDict(extra="forbid")

    tier: Literal["personal", "team", "org"]
    label: str


class Trend(BaseModel):
    model_config = ConfigDict(extra="forbid")

    direction: str
    value: float | int
    unit: str


class Kpi(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str
    value: float | int
    unit: str
    trend: Trend | None = None


class LineSeries(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    values: list[int]


class Line(BaseModel):
    model_config = ConfigDict(extra="forbid")

    labels: list[str]
    series: list[LineSeries]


class Stage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    label: str
    value: float | int


class ClosedCounts(BaseModel):
    model_config = ConfigDict(extra="forbid")

    won: int
    lost: int


class Pipeline(BaseModel):
    model_config = ConfigDict(extra="forbid")

    open_value: float | int
    stages: list[Stage]
    closed: ClosedCounts
    win_rate: float | int
    stalled: int


class Clients(BaseModel):
    model_config = ConfigDict(extra="forbid")

    active: int
    at_risk: int
    dormant: int
    churned: int


class Bands(BaseModel):
    model_config = ConfigDict(extra="forbid")

    promoter: int
    passive: int
    detractor: int


class Satisfaction(BaseModel):
    model_config = ConfigDict(extra="forbid")

    csat: float | None = None
    nps: int | None = None
    responses: int
    attention: int
    bands: Bands


class ByKind(BaseModel):
    model_config = ConfigDict(extra="forbid")

    task: int
    message: int


class FollowUps(BaseModel):
    model_config = ConfigDict(extra="forbid")

    overdue: int
    due_today: int
    by_kind: ByKind
    open: int


class BySeverity(BaseModel):
    model_config = ConfigDict(extra="forbid")

    danger: int
    warning: int
    info: int


class Worklist(BaseModel):
    model_config = ConfigDict(extra="forbid")

    total: int
    by_severity: BySeverity


class ReportContext(BaseModel):
    """The only record the provider receives — counts, labels and dates, never a person."""

    model_config = ConfigDict(extra="forbid")

    variant: Variant
    period: Period
    scope: Scope
    kpis: list[Kpi]
    line: Line
    pipeline: Pipeline
    clients: Clients
    satisfaction: Satisfaction
    follow_ups: FollowUps
    worklist: Worklist


class ReportRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    context: ReportContext


class Insight(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str = Field(min_length=1, max_length=40)
    title: str = Field(min_length=1, max_length=60)
    body: str = Field(min_length=1, max_length=220)


class Action(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str = Field(min_length=1, max_length=40)
    label: str = Field(min_length=1, max_length=50)
    description: str = Field(min_length=1, max_length=140)


class Usage(BaseModel):
    model_config = ConfigDict(extra="forbid")

    prompt_tokens: int | None = None
    completion_tokens: int | None = None
    total_tokens: int | None = None


class GeneratedReport(BaseModel):
    model_config = ConfigDict(extra="forbid")

    headline: str = Field(min_length=1, max_length=90)
    narrative: str = Field(min_length=1, max_length=700)
    insights: list[Insight]
    actions: list[Action]

    @field_validator("insights")
    @classmethod
    def exactly_three_insights(cls, value: list[Insight]) -> list[Insight]:
        if len(value) != 3:
            raise ValueError("exactly three insights are required")
        return value

    @field_validator("actions")
    @classmethod
    def exactly_four_actions(cls, value: list[Action]) -> list[Action]:
        if len(value) != 4:
            raise ValueError("exactly four actions are required")
        return value


class GeneratedReportResponse(GeneratedReport):
    model: str
    usage: Usage | None = None
