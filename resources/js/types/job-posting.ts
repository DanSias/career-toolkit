export type JobPostingSummary = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    has_source_url: boolean;
    source_url: string | null;
    captured_at: string | null;
};

export type JobPostingDetail = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    source_url: string | null;
    captured_at: string | null;
    description: string;
};

export type JobsIndexProps = {
    jobs: JobPostingSummary[];
};

export type JobShowProps = {
    job: JobPostingDetail;
};
