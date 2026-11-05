import type { ReactNode } from 'react'
import Billing from '../pages/Billing'
import Companies from '../pages/Companies'
import CompanyDetail from '../pages/CompanyDetail'
import CommunicationDetail from '../pages/CommunicationDetail'
import Communications from '../pages/Communications'
import CommunicationThreadDetail from '../pages/CommunicationThreadDetail'
import CommunicationThreads from '../pages/CommunicationThreads'
import CommunicationTemplateForm from '../pages/CommunicationTemplateForm'
import CommunicationTemplates from '../pages/CommunicationTemplates'
import Contacts from '../pages/Contacts'
import ContractDetail from '../pages/ContractDetail'
import Contracts from '../pages/Contracts'
import DashboardHome from '../pages/DashboardHome'
import Deployments from '../pages/Deployments'
import FollowUpForm from '../pages/FollowUpForm'
import FollowUps from '../pages/FollowUps'
import ImportLeads from '../pages/ImportLeads'
import LeadConvert from '../pages/LeadConvert'
import LeadDetail from '../pages/LeadDetail'
import LeadsList from '../pages/LeadsList'
import NewCommunication from '../pages/NewCommunication'
import NewCompany from '../pages/NewCompany'
import NewLead from '../pages/NewLead'
import NewOpportunity from '../pages/NewOpportunity'
import Opportunities from '../pages/Opportunities'
import OpportunityDetail from '../pages/OpportunityDetail'
import Pipeline from '../pages/Pipeline'
import Reports from '../pages/Reports'
import Satisfaction from '../pages/Satisfaction'
import SurveyForm from '../pages/SurveyForm'
import SurveyInvitations from '../pages/SurveyInvitations'
import SurveyResponseDetail from '../pages/SurveyResponseDetail'
import SurveyResponses from '../pages/SurveyResponses'
import Surveys from '../pages/Surveys'

export interface DashboardRoute {
  path: string
  element: ReactNode
}

/**
 * Every page behind the shared dashboard shell. Keeping the inventory in one exported list
 * lets the sidebar test prove that no navigation entry points at a route that does not exist
 * (spec 16 §Navigation and routes — S19).
 */
export const dashboardRoutes: DashboardRoute[] = [
  { path: '/dashboard', element: <DashboardHome /> },
  { path: '/leads', element: <LeadsList /> },
  { path: '/leads/new', element: <NewLead /> },
  { path: '/leads/import', element: <ImportLeads /> },
  { path: '/leads/:id', element: <LeadDetail /> },
  { path: '/leads/:id/convert', element: <LeadConvert /> },
  { path: '/accounts', element: <Companies /> },
  { path: '/accounts/new', element: <NewCompany /> },
  { path: '/accounts/:id', element: <CompanyDetail /> },
  { path: '/contracts', element: <Contracts /> },
  { path: '/contracts/:id', element: <ContractDetail /> },
  { path: '/deployments', element: <Deployments /> },
  { path: '/billing', element: <Billing /> },
  { path: '/contacts', element: <Contacts /> },
  { path: '/pipeline', element: <Pipeline /> },
  { path: '/opportunities', element: <Opportunities /> },
  { path: '/opportunities/new', element: <NewOpportunity /> },
  { path: '/opportunities/:id', element: <OpportunityDetail /> },
  { path: '/communications', element: <Communications /> },
  { path: '/communications/new', element: <NewCommunication /> },
  { path: '/communications/threads', element: <CommunicationThreads /> },
  { path: '/communications/threads/:threadId', element: <CommunicationThreadDetail /> },
  { path: '/communications/templates', element: <CommunicationTemplates /> },
  { path: '/communications/templates/new', element: <CommunicationTemplateForm /> },
  { path: '/communications/templates/:id', element: <CommunicationTemplateForm /> },
  { path: '/communications/:id', element: <CommunicationDetail /> },
  { path: '/follow-ups', element: <FollowUps /> },
  { path: '/follow-ups/new', element: <FollowUpForm /> },
  { path: '/follow-ups/:id', element: <FollowUpForm /> },
  { path: '/surveys', element: <Surveys /> },
  { path: '/surveys/new', element: <SurveyForm /> },
  { path: '/surveys/:id', element: <SurveyForm /> },
  { path: '/survey-invitations', element: <SurveyInvitations /> },
  { path: '/satisfaction', element: <Satisfaction /> },
  { path: '/satisfaction/responses', element: <SurveyResponses /> },
  { path: '/satisfaction/responses/:id', element: <SurveyResponseDetail /> },
  { path: '/reports', element: <Reports /> },
]
